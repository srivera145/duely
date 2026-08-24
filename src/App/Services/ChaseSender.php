<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Mail\MailTransport;
use Keel\App\Mail\OutboundMessage;
use Keel\App\Mail\SendResult;
use Keel\App\Mail\SmtpTransport;
use Keel\App\Models\Chase;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Models\SequenceStep;
use Keel\Core\Database;
use Keel\Core\Mailer;
use Throwable;

/**
 * Renders and sends the next reminder on each due chase.
 *
 * The shape of this class is dictated by one problem: an SMTP send is an
 * external side effect that cannot be rolled back, so it can never sit inside
 * the transaction that records it. The sequence is therefore:
 *
 *   1. In a transaction, take a row lock on the chase, re-check every hard
 *      stop, insert the chase_messages row as `queued`, and clear the chase's
 *      next_send_at so nothing else can pick it up. Commit.
 *   2. Stamp dispatched_at, then hand the message to the transport.
 *   3. In a second transaction, record the outcome and schedule the next step.
 *
 * A crash between 1 and 3 leaves a `queued` row. Whether it was delivered is
 * unknowable, so `dispatched_at` decides: unset means nothing left the
 * building and a retry is safe; set means it may have gone, and we never
 * resend. A client receiving the same chase twice is worse than receiving it
 * once — and the unique index on (chase_id, position) makes a duplicate row
 * impossible even if this logic were wrong.
 */
class ChaseSender
{
    /** Retry delays in seconds: roughly 5 minutes, 25 minutes, two hours. */
    private const BACKOFF_SECONDS = [300, 1500, 7200];
    private const MAX_ATTEMPTS = 3;

    /**
     * A queued row older than this with dispatched_at set is treated as an
     * interrupted send and closed out rather than retried.
     */
    private const INTERRUPTED_AFTER_MINUTES = 15;

    public function __construct(
        private readonly MailTransport $transport = new SmtpTransport(),
        private readonly TemplateRenderer $renderer = new TemplateRenderer(),
        private readonly ChaseScheduler $scheduler = new ChaseScheduler(),
        private readonly SendRateLimiter $limiter = new SendRateLimiter(),
    ) {
    }

    /**
     * Process every chase currently due for one tenant.
     *
     * @param callable|null $sleeper injected so tests do not actually wait
     * @return array{sent:int, skipped:int, failed:int, results:array<int, array>}
     */
    public function processDueForTenant(
        int $tenantId,
        ?DateTimeImmutable $now = null,
        int $limit = 50,
        ?callable $sleeper = null
    ): array {
        $now ??= Clock::now();

        $this->recoverInterrupted($tenantId, $now);

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        for ($processed = 0; $processed < $limit; $processed++) {
            $outcome = $this->processNext($tenantId, $now);

            if ($outcome === null) {
                break;
            }

            $results[] = $outcome;

            match ($outcome['outcome']) {
                'sent' => $sent++,
                'failed' => $failed++,
                default => $skipped++,
            };

            // Space real sends out. Skips cost nothing and need no pause.
            if ($outcome['outcome'] === 'sent' && $sleeper !== null) {
                $sleeper($this->limiter->jitterSeconds());
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'results' => $results];
    }

    /**
     * Claim, send, and record one chase. Returns null when nothing is due.
     *
     * @return array{chase_id:int, outcome:string, reason:?string, message_id:?int}|null
     */
    /**
     * @param bool $ignoreWindow relax ONLY the time-of-day rule, for a user
     *                           pressing "send now". Every hard stop still
     *                           applies — a paid invoice is never emailed
     *                           because someone clicked a button.
     */
    public function processNext(int $tenantId, ?DateTimeImmutable $now = null, bool $ignoreWindow = false): ?array
    {
        $now ??= Clock::now();

        $claim = $this->claim($tenantId, $now, $ignoreWindow);

        if ($claim === null) {
            return null;
        }

        if ($claim['outcome'] !== 'claimed') {
            return [
                'chase_id' => $claim['chase_id'],
                'outcome' => $claim['outcome'],
                'reason' => $claim['reason'],
                'message_id' => null,
            ];
        }

        return $this->dispatch($tenantId, $claim, $now);
    }

    // ------------------------------------------------------------- claiming

    /**
     * Lock one due chase and stage its next message.
     *
     * Everything that could make sending wrong is re-checked inside the lock,
     * because minutes may have passed since the chase was scheduled — the
     * invoice may have been paid in that window.
     *
     * @return array{chase_id:int, outcome:string, reason:?string, message:?array, context:?array}|null
     */
    private function claim(int $tenantId, DateTimeImmutable $now, bool $ignoreWindow = false): ?array
    {
        $connection = Database::connection();
        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            // SKIP LOCKED lets several workers drain the queue at once without
            // blocking on each other or handing the same chase to two of them.
            $select = $connection->prepare(
                'SELECT * FROM chases
                 WHERE tenant_id = ?
                   AND status IN (?, ?)
                   AND next_send_at IS NOT NULL
                   AND next_send_at <= ?
                 ORDER BY next_send_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED'
            );
            $select->execute([
                $tenantId,
                Chase::STATUS_SCHEDULED,
                Chase::STATUS_ACTIVE,
                Clock::toDatabase($now),
            ]);

            $chase = $select->fetch();

            if (!$chase) {
                if ($openedTransaction) {
                    $connection->commit();
                }

                return null;
            }

            $chaseId = (int) $chase['id'];
            $context = $this->loadContext($tenantId, $chase);

            // --- hard stops, all re-checked under the lock ------------------
            $stop = $this->hardStop($context);

            if ($stop !== null) {
                $this->cancel($tenantId, $chaseId, $stop['status'], $stop['reason']);
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['chase_id' => $chaseId, 'outcome' => 'cancelled', 'reason' => $stop['message'], 'message' => null, 'context' => null];
            }

            $invoice = $context['invoice'];
            $sequence = $context['sequence'];
            $account = $context['account'];

            // A retry that is already staged is resumed rather than re-created.
            $pending = $this->pendingRetry($tenantId, $chaseId, $now);

            if ($pending !== null) {
                $this->parkChase($tenantId, $chaseId);
                if ($openedTransaction) {
                    $connection->commit();
                }

                return [
                    'chase_id' => $chaseId,
                    'outcome' => 'claimed',
                    'reason' => null,
                    'message' => $pending,
                    'context' => $context,
                ];
            }

            $step = SequenceStep::nextAfter($tenantId, (int) $sequence['id'], (int) $chase['current_position']);

            if ($step === null) {
                Chase::advance($tenantId, $chaseId, (int) $chase['current_position'], null);
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['chase_id' => $chaseId, 'outcome' => 'completed', 'reason' => 'No reminders left in this sequence.', 'message' => null, 'context' => null];
            }

            // --- rate limit -------------------------------------------------
            $allowance = $this->limiter->check($tenantId, (int) $account['id'], $now);

            if (!$allowance['allowed']) {
                Chase::update($tenantId, $chaseId, [
                    'next_send_at' => Clock::toDatabase($allowance['retry_after'] ?? $now->modify('+15 minutes')),
                ]);
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['chase_id' => $chaseId, 'outcome' => 'rate_limited', 'reason' => $allowance['reason'], 'message' => null, 'context' => null];
            }

            // --- send window ------------------------------------------------
            // Re-checked here so a backlog cleared at 4am does not email
            // everyone at 4am. A deliberate "send now" is the one case where
            // the user has overruled the clock — the hard stops above are not
            // negotiable either way.
            if (!$ignoreWindow && !$this->scheduler->isWithinWindow($now, $sequence, $invoice)) {
                $nextSlot = $this->scheduler->nextWindowSlot($now, $sequence, $invoice, $now);

                Chase::update($tenantId, $chaseId, ['next_send_at' => Clock::toDatabase($nextSlot)]);
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['chase_id' => $chaseId, 'outcome' => 'outside_window', 'reason' => 'Waiting for the send window.', 'message' => null, 'context' => null];
            }

            // --- stage the message ------------------------------------------
            $messageId = $this->stageMessage($tenantId, $chase, $context, $step, $now);

            if ($messageId === null) {
                // The unique index refused a second row at this position, which
                // means another run already staged it. Leave it alone.
                $this->parkChase($tenantId, $chaseId);
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['chase_id' => $chaseId, 'outcome' => 'already_staged', 'reason' => 'A message for this step already exists.', 'message' => null, 'context' => null];
            }

            // Move the chase onto this step and clear next_send_at, so nothing
            // can claim it again until the outcome is recorded.
            Chase::update($tenantId, $chaseId, [
                'status' => Chase::STATUS_ACTIVE,
                'current_position' => (int) $step['position'],
                'next_send_at' => null,
            ]);

            if ($openedTransaction) {
                $connection->commit();
            }

            return [
                'chase_id' => $chaseId,
                'outcome' => 'claimed',
                'reason' => null,
                'message' => ChaseMessage::find($tenantId, $messageId),
                'context' => $context,
            ];
        } catch (Throwable $exception) {
            if ($openedTransaction && $connection->inTransaction()) {
                    $connection->rollBack();
                }

            throw $exception;
        }
    }

    /**
     * Insert the queued row. Returns null if one already exists at this position.
     */
    private function stageMessage(int $tenantId, array $chase, array $context, array $step, DateTimeImmutable $now): ?int
    {
        $invoice = $context['invoice'];
        $account = $context['account'];
        $chaseId = (int) $chase['id'];

        $rendered = $this->renderer->renderMessage(
            (string) $step['subject_template'],
            (string) $step['body_template'],
            TemplateRenderer::contextFor($invoice, (string) $account['from_name'], $now)
        );

        $rfcMessageId = ChaseMessage::newMessageId();
        $rootMessageId = $chase['root_message_id'] ?: null;

        // Step one starts the thread; every later step hangs off its Message-ID
        // so the client sees one conversation instead of a pile of emails.
        $inReplyTo = $rootMessageId;
        $references = $rootMessageId === null
            ? null
            : ChaseMessage::referencesFor($tenantId, $chaseId, $rootMessageId);

        try {
            return ChaseMessage::create($tenantId, [
                'chase_id' => $chaseId,
                'email_account_id' => (int) $account['id'],
                'sequence_step_id' => (int) $step['id'],
                'position' => (int) $step['position'],
                'to_email' => (string) $invoice['client_email'],
                'from_email' => (string) $account['from_email'],
                'subject' => $rendered['subject'],
                'body_text' => $rendered['text'],
                'body_html' => $rendered['html'],
                'rfc_message_id' => $rfcMessageId,
                'in_reply_to' => $inReplyTo,
                'references_header' => $references,
                'status' => ChaseMessage::STATUS_QUEUED,
                'scheduled_for' => Clock::toDatabase($now),
                'attempts' => 0,
            ]);
        } catch (\PDOException $exception) {
            if (str_contains($exception->getMessage(), '1062')) {
                return null;
            }

            throw $exception;
        }
    }

    // ------------------------------------------------------------ dispatch

    /**
     * Send a staged message and record what happened.
     *
     * @return array{chase_id:int, outcome:string, reason:?string, message_id:?int}
     */
    private function dispatch(int $tenantId, array $claim, DateTimeImmutable $now): array
    {
        $message = $claim['message'];
        $context = $claim['context'];
        $chaseId = (int) $claim['chase_id'];
        $messageId = (int) $message['id'];

        $account = $context['account'];
        $invoice = $context['invoice'];

        // Stamped before the send, outside any transaction, so a crash leaves
        // evidence that something may already have gone out.
        ChaseMessage::update($tenantId, $messageId, [
            'dispatched_at' => Clock::toDatabase(Clock::now()),
            'attempts' => (int) $message['attempts'] + 1,
        ]);

        $outbound = new OutboundMessage(
            toEmail: (string) $message['to_email'],
            toName: (string) ($invoice['client_name'] ?? ''),
            fromEmail: (string) $message['from_email'],
            fromName: (string) $account['from_name'],
            replyTo: $account['reply_to'] ?: null,
            subject: (string) $message['subject'],
            textBody: (string) $message['body_text'],
            htmlBody: (string) $message['body_html'],
            messageId: (string) $message['rfc_message_id'],
            inReplyTo: $message['in_reply_to'] ?: null,
            references: $message['references_header'] ?: null,
        );

        try {
            $result = $this->transport->send($account, $outbound);
        } catch (Throwable $exception) {
            // A transport is not supposed to throw, but one that does must not
            // take the worker with it.
            $result = SendResult::transientFailure(
                'The mail transport failed unexpectedly.',
                ConnectionDiagnosis::UNKNOWN,
                $exception->getMessage()
            );
        }

        return $result->sent
            ? $this->recordSuccess($tenantId, $chaseId, $messageId, $outbound, $context, $now)
            : $this->recordFailure($tenantId, $chaseId, $message, $result, $context, $now);
    }

    /**
     * @return array{chase_id:int, outcome:string, reason:?string, message_id:int}
     */
    private function recordSuccess(
        int $tenantId,
        int $chaseId,
        int $messageId,
        OutboundMessage $outbound,
        array $context,
        DateTimeImmutable $now
    ): array {
        $connection = Database::connection();
        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            ChaseMessage::markSent($tenantId, $messageId);

            $chase = Chase::find($tenantId, $chaseId);

            // The first message anchors the thread everything else replies to.
            if ($chase !== null && empty($chase['root_message_id'])) {
                Chase::anchorThread($tenantId, $chaseId, $outbound->messageId);
            }

            $plan = $this->scheduler->planNext(
                $tenantId,
                $chase ?? [],
                $context['invoice'],
                $context['sequence'],
                (int) ($chase['current_position'] ?? 0),
                $now
            );

            Chase::advance(
                $tenantId,
                $chaseId,
                (int) ($chase['current_position'] ?? 0),
                $plan['next_send_at']
            );

            if ($openedTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if ($openedTransaction && $connection->inTransaction()) {
                    $connection->rollBack();
                }

            throw $exception;
        }

        return ['chase_id' => $chaseId, 'outcome' => 'sent', 'reason' => null, 'message_id' => $messageId];
    }

    /**
     * @return array{chase_id:int, outcome:string, reason:?string, message_id:int}
     */
    private function recordFailure(
        int $tenantId,
        int $chaseId,
        array $message,
        SendResult $result,
        array $context,
        DateTimeImmutable $now
    ): array {
        $messageId = (int) $message['id'];
        $attempts = (int) $message['attempts'] + 1;
        $account = $context['account'];

        $exhausted = !$result->retryable || $attempts >= self::MAX_ATTEMPTS;

        $connection = Database::connection();
        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            if (!$exhausted) {
                $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];
                $retryAt = $now->modify('+' . $delay . ' seconds');

                // Put the message back in the queue and point the chase at it,
                // so the ordinary claim loop picks the retry up.
                ChaseMessage::update($tenantId, $messageId, [
                    'status' => ChaseMessage::STATUS_QUEUED,
                    'attempts' => $attempts,
                    'next_attempt_at' => Clock::toDatabase($retryAt),
                    'dispatched_at' => null,
                    'failed_reason' => $result->message,
                ]);

                Chase::update($tenantId, $chaseId, [
                    'next_send_at' => Clock::toDatabase($retryAt),
                    // Step back so the retry targets the same rung.
                    'current_position' => (int) $message['position'] - 1,
                ]);

                if ($openedTransaction) {
                    $connection->commit();
                }

                return [
                    'chase_id' => $chaseId,
                    'outcome' => 'retry_scheduled',
                    'reason' => $result->message . ' Retrying at ' . $retryAt->format('H:i') . '.',
                    'message_id' => $messageId,
                ];
            }

            ChaseMessage::update($tenantId, $messageId, [
                'status' => ChaseMessage::STATUS_FAILED,
                'attempts' => $attempts,
                'next_attempt_at' => null,
                'failed_reason' => $result->message,
            ]);

            // Exhausting the retries flips the mailbox to needs_reauth below, so
            // the chase has to agree — otherwise the settings banner and the
            // invoice list would tell the user two different stories.
            $blamesMailbox = $result->needsReauth || $attempts >= self::MAX_ATTEMPTS;

            Chase::pause($tenantId, $chaseId, $blamesMailbox
                ? Chase::PAUSE_NEEDS_REAUTH
                : Chase::PAUSE_MANUAL);

            if ($openedTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if ($openedTransaction && $connection->inTransaction()) {
                    $connection->rollBack();
                }

            throw $exception;
        }

        // Outside the transaction: the mailbox is broken, so tell the user
        // through Duely's own mail rather than through theirs.
        if ($result->needsReauth || $attempts >= self::MAX_ATTEMPTS) {
            $this->reportBrokenMailbox($tenantId, $account, $result);
        }

        return [
            'chase_id' => $chaseId,
            'outcome' => 'failed',
            'reason' => $result->message,
            'message_id' => $messageId,
        ];
    }

    // ------------------------------------------------------------ recovery

    /**
     * Close out messages left `queued` by a crash.
     *
     * A row that was never dispatched is released for a normal retry. A row
     * that was dispatched is closed as failed and never resent — we cannot
     * know whether it arrived, and sending a client the same chase twice is
     * the worse outcome.
     *
     * @return array{released:int, abandoned:int}
     */
    public function recoverInterrupted(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $cutoff = Clock::toDatabase($now->modify('-' . self::INTERRUPTED_AFTER_MINUTES . ' minutes'));

        $sql = 'SELECT * FROM chase_messages
                WHERE tenant_id = ?
                  AND status = ?
                  AND scheduled_for IS NOT NULL
                  AND scheduled_for <= ?
                  AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
                ORDER BY id ASC
                LIMIT 100';

        $statement = Database::connection()->prepare($sql);
        $statement->execute([$tenantId, ChaseMessage::STATUS_QUEUED, $cutoff, Clock::toDatabase($now)]);

        $released = 0;
        $abandoned = 0;

        foreach ($statement->fetchAll() as $message) {
            $chaseId = (int) $message['chase_id'];
            $chase = Chase::find($tenantId, $chaseId);

            if ($chase === null || $chase['next_send_at'] !== null) {
                // The chase is already scheduled to retry; nothing stranded.
                continue;
            }

            if ($message['dispatched_at'] === null) {
                // Nothing left the building: safe to try again.
                //
                // next_attempt_at is set as well as next_send_at, because the
                // claim path resumes an existing queued row through
                // pendingRetry(), which keys off that column. Without it the
                // claim would try to stage a second row at the same position,
                // hit the unique index, and quietly park the chase forever.
                ChaseMessage::update($tenantId, (int) $message['id'], [
                    'next_attempt_at' => Clock::toDatabase($now),
                    'attempts' => max(1, (int) $message['attempts']),
                ]);

                Chase::update($tenantId, $chaseId, [
                    'next_send_at' => Clock::toDatabase($now),
                    'current_position' => (int) $message['position'] - 1,
                ]);
                $released++;
                continue;
            }

            // It may have been delivered. Do not resend; move on to the next
            // rung so the ladder is not stuck forever on this one.
            ChaseMessage::update($tenantId, (int) $message['id'], [
                'status' => ChaseMessage::STATUS_FAILED,
                'failed_reason' => 'Interrupted mid-send. Not resent, to avoid delivering this reminder twice.',
            ]);

            $context = $this->loadContext($tenantId, $chase);

            if ($context['invoice'] !== null && $context['sequence'] !== null) {
                $plan = $this->scheduler->planNext(
                    $tenantId,
                    $chase,
                    $context['invoice'],
                    $context['sequence'],
                    (int) $message['position'],
                    $now
                );

                Chase::advance($tenantId, $chaseId, (int) $message['position'], $plan['next_send_at']);
            }

            $abandoned++;
        }

        return ['released' => $released, 'abandoned' => $abandoned];
    }

    // ------------------------------------------------------------ internals

    /**
     * Everything a send depends on, loaded once under the lock.
     *
     * @return array{invoice:?array, client:?array, sequence:?array, account:?array, chase:array}
     */
    private function loadContext(int $tenantId, array $chase): array
    {
        $invoice = Invoice::withClient($tenantId, (int) $chase['invoice_id']);
        $client = $invoice === null ? null : Client::find($tenantId, (int) $invoice['client_id']);
        $sequence = Sequence::find($tenantId, (int) $chase['sequence_id']);

        $account = $chase['email_account_id'] !== null
            ? EmailAccount::find($tenantId, (int) $chase['email_account_id'])
            : EmailAccount::sendingAccount($tenantId);

        return [
            'invoice' => $invoice,
            'client' => $client,
            'sequence' => $sequence,
            'account' => $account,
            'chase' => $chase,
        ];
    }

    /**
     * The conditions that cancel a message instead of sending it.
     *
     * Checked immediately before every send, inside the row lock, because the
     * gap between scheduling and sending is exactly when an invoice gets paid.
     *
     * @return array{status:string, reason:string, message:string}|null
     */
    private function hardStop(array $context): ?array
    {
        $chase = $context['chase'];
        $invoice = $context['invoice'];
        $client = $context['client'];
        $sequence = $context['sequence'];
        $account = $context['account'];

        if ($invoice === null) {
            return ['status' => Chase::STATUS_STOPPED, 'reason' => Chase::PAUSE_MANUAL, 'message' => 'The invoice no longer exists.'];
        }

        if ($invoice['status'] !== Invoice::STATUS_OPEN) {
            return [
                'status' => Chase::STATUS_PAUSED,
                'reason' => $invoice['status'] === Invoice::STATUS_PAID ? Chase::PAUSE_INVOICE_PAID : Chase::PAUSE_MANUAL,
                'message' => 'The invoice is marked ' . $invoice['status'] . '.',
            ];
        }

        if (!in_array($chase['status'], [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE], true)) {
            return ['status' => Chase::STATUS_PAUSED, 'reason' => Chase::PAUSE_MANUAL, 'message' => 'This chase is ' . $chase['status'] . '.'];
        }

        if ($client !== null && $client['suppressed_at'] !== null) {
            return ['status' => Chase::STATUS_STOPPED, 'reason' => Chase::PAUSE_MANUAL, 'message' => 'This client is suppressed.'];
        }

        if ($sequence === null) {
            return ['status' => Chase::STATUS_PAUSED, 'reason' => Chase::PAUSE_MANUAL, 'message' => 'The sequence no longer exists.'];
        }

        if ($account === null) {
            return ['status' => Chase::STATUS_PAUSED, 'reason' => Chase::PAUSE_NEEDS_REAUTH, 'message' => 'No mailbox is connected.'];
        }

        if ($account['status'] !== EmailAccount::STATUS_ACTIVE) {
            return [
                'status' => Chase::STATUS_PAUSED,
                'reason' => Chase::PAUSE_NEEDS_REAUTH,
                'message' => 'The mailbox is ' . $account['status'] . '.',
            ];
        }

        return null;
    }

    private function cancel(int $tenantId, int $chaseId, string $status, string $reason): void
    {
        $status === Chase::STATUS_STOPPED
            ? Chase::stop($tenantId, $chaseId)
            : Chase::pause($tenantId, $chaseId, $reason);
    }

    /**
     * Stop a chase being re-claimed without changing its status.
     */
    private function parkChase(int $tenantId, int $chaseId): void
    {
        Chase::update($tenantId, $chaseId, ['next_send_at' => null]);
    }

    /**
     * A queued message on this chase that is due for another attempt.
     */
    private function pendingRetry(int $tenantId, int $chaseId, DateTimeImmutable $now): ?array
    {
        $sql = 'SELECT * FROM chase_messages
                WHERE tenant_id = ?
                  AND chase_id = ?
                  AND status = ?
                  AND attempts > 0
                  AND attempts < ?
                  AND next_attempt_at IS NOT NULL
                  AND next_attempt_at <= ?
                ORDER BY position DESC
                LIMIT 1';

        $statement = Database::connection()->prepare($sql);
        $statement->execute([
            $tenantId,
            $chaseId,
            ChaseMessage::STATUS_QUEUED,
            self::MAX_ATTEMPTS,
            Clock::toDatabase($now),
        ]);

        return $statement->fetch() ?: null;
    }

    /**
     * Tell the user their mailbox has stopped working.
     *
     * Sent through Duely's own mail configuration, because the whole reason we
     * are here is that theirs will not accept anything.
     */
    private function reportBrokenMailbox(int $tenantId, ?array $account, SendResult $result): void
    {
        if ($account === null) {
            return;
        }

        EmailAccount::markNeedsReauth($tenantId, (int) $account['id'], $result->message);

        $owner = $this->tenantOwner($tenantId);

        if ($owner === null) {
            return;
        }

        $fromEmail = htmlspecialchars((string) $account['from_email'], ENT_QUOTES, 'UTF-8');
        $reason = htmlspecialchars($result->message, ENT_QUOTES, 'UTF-8');
        $appUrl = rtrim((string) \Keel\Core\Env::get('APP_URL', ''), '/');

        $body = '<p>Duely has paused your invoice reminders.</p>'
            . '<p>We tried to send from <strong>' . $fromEmail . '</strong> and the server said:</p>'
            . '<p style="padding:12px;background:#f5f5f5;border-radius:6px;">' . $reason . '</p>'
            . '<p>Nothing has been lost — your chases are paused and will pick up where they left off '
            . 'once the mailbox is reconnected.</p>'
            . '<p><a href="' . htmlspecialchars($appUrl . '/settings/email', ENT_QUOTES, 'UTF-8') . '">Reconnect your mailbox</a></p>';

        Mailer::send(
            (string) $owner['email'],
            (string) ($owner['name'] ?? ''),
            'Your invoice reminders are paused',
            $body
        );
    }

    private function tenantOwner(int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, email, name FROM users
             WHERE organization_id = ?
             ORDER BY (role = ?) DESC, id ASC
             LIMIT 1'
        );
        $statement->execute([$tenantId, 'owner']);

        return $statement->fetch() ?: null;
    }
}
