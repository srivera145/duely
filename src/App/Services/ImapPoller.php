<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\ReplyEvent;
use Keel\Core\Activity;
use Keel\Core\Env;
use Keel\Core\Mailer;
use Throwable;

/**
 * Reads each connected mailbox for replies and bounces, and acts on them.
 *
 * This is the safety net under the cadence engine. Without it, a client who
 * answers "cheque went out Friday" still receives a Final Notice, which is the
 * one failure that would make someone stop trusting Duely entirely.
 *
 * Two properties are non-negotiable:
 *
 *   Read-only. The mailbox is opened with EXAMINE and every fetch uses
 *   BODY.PEEK, so nothing is marked read, moved, or deleted. Duely is a guest
 *   in someone's personal inbox.
 *
 *   Idempotent. Polling walks an overlapping UID window on purpose, so the same
 *   message will be seen more than once. The unique index on
 *   (email_account_id, provider_message_id) means a re-poll can never create a
 *   second event or pause a chase twice.
 */
class ImapPoller
{
    /** Messages examined per account per run. */
    private const BATCH = 200;

    /**
     * Re-examine this many UIDs below the cursor.
     *
     * A message can arrive with a lower UID than one already seen, and a poll
     * interrupted halfway can advance the cursor past unprocessed mail. Dedupe
     * makes the overlap free, so it is cheap insurance against a missed reply.
     */
    private const OVERLAP = 5;

    public function __construct(
        private readonly ImapClient $client = new ImapClient(),
        private readonly ReplyMatcher $matcher = new ReplyMatcher(),
    ) {
    }

    /**
     * Poll every pollable mailbox for one tenant.
     *
     * @return array{accounts:int, examined:int, recorded:int, paused:int, stopped:int, errors:string[]}
     */
    public function pollTenant(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $totals = ['accounts' => 0, 'examined' => 0, 'recorded' => 0, 'paused' => 0, 'stopped' => 0, 'errors' => []];

        foreach (EmailAccount::pollable($tenantId) as $account) {
            $totals['accounts']++;

            try {
                $result = $this->pollAccount($tenantId, $account, $now);

                $totals['examined'] += $result['examined'];
                $totals['recorded'] += $result['recorded'];
                $totals['paused'] += $result['paused'];
                $totals['stopped'] += $result['stopped'];
            } catch (Throwable $exception) {
                // One unreachable mailbox must not stop the others.
                $totals['errors'][] = 'Mailbox ' . (int) $account['id'] . ': ' . $exception->getMessage();
                error_log('[Duely] Inbox poll failed for account ' . (int) $account['id'] . ': ' . $exception->getMessage());
            }
        }

        return $totals;
    }

    /**
     * Poll one mailbox.
     *
     * @return array{examined:int, recorded:int, paused:int, stopped:int, cursor:?int}
     */
    public function pollAccount(int $tenantId, array $account, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $accountId = (int) $account['id'];
        $folder = (string) ($account['imap_folder'] ?? 'INBOX');

        $result = ['examined' => 0, 'recorded' => 0, 'paused' => 0, 'stopped' => 0, 'cursor' => null];

        $password = EmailAccount::imapPassword($account);

        if ($password === null || $password === '') {
            $this->recordAccountError($tenantId, $accountId, 'No saved IMAP password.');

            return $result;
        }

        try {
            $connected = $this->client->connect(
                (string) $account['imap_host'],
                (int) $account['imap_port'],
                (string) $account['imap_encryption']
            );

            if (!$connected->succeeded()) {
                $this->recordAccountError($tenantId, $accountId, $connected->message);

                return $result;
            }

            $loggedIn = $this->client->login(
                (string) $account['imap_username'],
                $password,
                (string) $account['provider']
            );

            if (!$loggedIn->succeeded()) {
                $this->recordAccountError($tenantId, $accountId, $loggedIn->message);

                // A mailbox that will not accept us needs the user's attention,
                // and the cadence engine should stop trying to send through it.
                if ($loggedIn->isAuthProblem()) {
                    EmailAccount::markNeedsReauth($tenantId, $accountId, $loggedIn->message);
                }

                return $result;
            }

            // EXAMINE, not SELECT: read-only at the protocol level.
            $mailbox = $this->client->examine($folder);

            if (!$mailbox['ok']) {
                $this->recordAccountError($tenantId, $accountId, $mailbox['diagnosis']->message);

                return $result;
            }

            $cursor = $this->resolveCursor($account, $mailbox);
            $uids = $this->client->uidsFrom($cursor);

            // The server may answer a `n:*` range with the last UID even when
            // nothing new exists, so filter rather than trusting the range.
            $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid >= $cursor));
            sort($uids);
            $uids = array_slice($uids, 0, self::BATCH);

            $highest = $cursor;

            foreach ($uids as $uid) {
                $fetched = $this->client->fetchMessage($uid);
                $result['examined']++;
                $highest = max($highest, $uid);

                if ($fetched === null) {
                    continue;
                }

                $message = InboundMessage::parse($uid, $fetched['headers'], $fetched['body']);
                $outcome = $this->handle($tenantId, $account, $message, $now);

                $result['recorded'] += $outcome['recorded'] ? 1 : 0;
                $result['paused'] += $outcome['paused'] ? 1 : 0;
                $result['stopped'] += $outcome['stopped'] ? 1 : 0;
            }

            // Advance the cursor, keeping a small overlap.
            $nextCursor = max(1, ($mailbox['uidnext'] ?? ($highest + 1)) - self::OVERLAP);

            EmailAccount::update($tenantId, $accountId, [
                'imap_uidnext' => $nextCursor,
                'imap_uidvalidity' => $mailbox['uidvalidity'],
                'imap_last_seen_uid' => $highest,
                'imap_last_polled_at' => Clock::toDatabase($now),
                'imap_last_error' => null,
            ]);

            $result['cursor'] = $nextCursor;

            return $result;
        } finally {
            $this->client->disconnect();
        }
    }

    /**
     * Record one inbound message and take whatever action it calls for.
     *
     * @return array{recorded:bool, paused:bool, stopped:bool, type:string}
     */
    public function handle(int $tenantId, array $account, InboundMessage $message, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $accountId = (int) $account['id'];

        $match = $this->matcher->match($tenantId, $message, $now);

        // Account plus UID is stable and always present, unlike Message-ID.
        $providerMessageId = $accountId . ':' . $message->uid;

        $eventId = ReplyEvent::record($tenantId, [
            'chase_id' => $match['chase_id'],
            'chase_message_id' => $match['chase_message_id'],
            'email_account_id' => $accountId,
            'provider_message_id' => $providerMessageId,
            'provider_uid' => $message->uid,
            'type' => $match['type'],
            'matched_by' => $match['matched_by'],
            'is_hard_bounce' => $match['is_hard_bounce'] ? 1 : 0,
            'from_email' => $message->fromEmail,
            'subject' => mb_substr($message->subject, 0, 500),
            // A short extract only. The message body is never stored.
            'snippet' => $message->snippet,
            'rfc_message_id' => $message->messageId ?? '<duely-' . $providerMessageId . '@local>',
            'in_reply_to' => $message->inReplyTo,
            'thread_id' => $message->threadId,
            'raw_headers' => mb_substr($message->rawHeaders, 0, 16000),
            'received_at' => Clock::toDatabase($message->receivedAt ?? $now),
        ]);

        // Already seen on an earlier poll: do nothing at all.
        if ($eventId === null) {
            return ['recorded' => false, 'paused' => false, 'stopped' => false, 'type' => $match['type']];
        }

        if ($match['chase_id'] === null) {
            ReplyEvent::markProcessed($tenantId, $eventId, 'unmatched');

            return ['recorded' => true, 'paused' => false, 'stopped' => false, 'type' => $match['type']];
        }

        return match ($match['type']) {
            ReplyEvent::TYPE_REPLY => $this->handleReply($tenantId, $eventId, $match, $message, $now),
            ReplyEvent::TYPE_BOUNCE => $this->handleBounce($tenantId, $eventId, $match, $message, $now),
            ReplyEvent::TYPE_COMPLAINT => $this->handleComplaint($tenantId, $eventId, $match, $message, $now),
            // An out-of-office is not an answer. Recorded, but the ladder keeps
            // climbing — otherwise a fortnight's holiday silently kills a chase.
            default => $this->handleAutoReply($tenantId, $eventId, $match),
        };
    }

    // -------------------------------------------------------------- actions

    private function handleReply(int $tenantId, int $eventId, array $match, InboundMessage $message, DateTimeImmutable $now): array
    {
        $chaseId = (int) $match['chase_id'];
        $chase = Chase::find($tenantId, $chaseId);

        if ($chase === null) {
            ReplyEvent::markProcessed($tenantId, $eventId, 'chase_missing');

            return ['recorded' => true, 'paused' => false, 'stopped' => false, 'type' => ReplyEvent::TYPE_REPLY];
        }

        $alreadyQuiet = !in_array($chase['status'], [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE], true);

        if (!$alreadyQuiet) {
            Chase::pause($tenantId, $chaseId, Chase::PAUSE_CLIENT_REPLIED);
        }

        ReplyEvent::markProcessed($tenantId, $eventId, $alreadyQuiet ? 'already_paused' : 'paused_chase');

        Activity::log('chase.client_replied', 'Chase', $chaseId, [
            'from' => $message->fromEmail,
            'matched_by' => $match['matched_by'],
        ]);

        if (!$alreadyQuiet) {
            $this->notifyReply($tenantId, $chaseId, $message);
        }

        return ['recorded' => true, 'paused' => !$alreadyQuiet, 'stopped' => false, 'type' => ReplyEvent::TYPE_REPLY];
    }

    private function handleBounce(int $tenantId, int $eventId, array $match, InboundMessage $message, DateTimeImmutable $now): array
    {
        $chaseId = (int) $match['chase_id'];

        // A soft bounce is a full mailbox or a transient outage. Record it, but
        // do not abandon a client over a temporary problem.
        if (!$match['is_hard_bounce']) {
            ReplyEvent::markProcessed($tenantId, $eventId, 'soft_bounce_noted');

            return ['recorded' => true, 'paused' => false, 'stopped' => false, 'type' => ReplyEvent::TYPE_BOUNCE];
        }

        Chase::stop($tenantId, $chaseId);

        $invoice = $this->invoiceForChase($tenantId, $chaseId);

        if ($invoice !== null) {
            Client::update($tenantId, (int) $invoice['client_id'], [
                'email_invalid_at' => Clock::toDatabase($now),
                'email_invalid_reason' => mb_substr('Mail to this address bounced: ' . $message->subject, 0, 255),
            ]);
        }

        ReplyEvent::markProcessed($tenantId, $eventId, 'stopped_chase');

        Activity::log('chase.bounced', 'Chase', $chaseId, ['from' => $message->fromEmail]);

        $this->notifyBounce($tenantId, $chaseId, $invoice, $message);

        return ['recorded' => true, 'paused' => false, 'stopped' => true, 'type' => ReplyEvent::TYPE_BOUNCE];
    }

    /**
     * A spam complaint is the strongest possible "stop emailing me".
     */
    private function handleComplaint(int $tenantId, int $eventId, array $match, InboundMessage $message, DateTimeImmutable $now): array
    {
        $chaseId = (int) $match['chase_id'];

        Chase::stop($tenantId, $chaseId);

        $invoice = $this->invoiceForChase($tenantId, $chaseId);

        if ($invoice !== null) {
            Client::update($tenantId, (int) $invoice['client_id'], [
                'suppressed_at' => Clock::toDatabase($now),
                'suppressed_reason' => 'complaint',
            ]);
        }

        ReplyEvent::markProcessed($tenantId, $eventId, 'suppressed_client');
        Activity::log('chase.complaint', 'Chase', $chaseId, ['from' => $message->fromEmail]);

        return ['recorded' => true, 'paused' => false, 'stopped' => true, 'type' => ReplyEvent::TYPE_COMPLAINT];
    }

    private function handleAutoReply(int $tenantId, int $eventId, array $match): array
    {
        ReplyEvent::markProcessed($tenantId, $eventId, 'auto_reply_ignored');

        return ['recorded' => true, 'paused' => false, 'stopped' => false, 'type' => ReplyEvent::TYPE_AUTO_REPLY];
    }

    // -------------------------------------------------------- notifications

    /**
     * Tell the user their client answered, and what they said.
     *
     * The in-app trail is the activity log plus the reply event itself, which
     * the inbox view reads. The email exists because the whole point is that
     * the user finds out before they wonder why chasing stopped.
     */
    private function notifyReply(int $tenantId, int $chaseId, InboundMessage $message): void
    {
        $owner = $this->tenantOwner($tenantId);

        if ($owner === null) {
            return;
        }

        $context = Chase::withContext($tenantId, $chaseId);
        $invoiceNumber = (string) ($context['invoice_number'] ?? 'an invoice');
        $clientName = (string) ($context['client_name'] ?? $message->fromEmail);

        $safeClient = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
        $safeNumber = htmlspecialchars($invoiceNumber, ENT_QUOTES, 'UTF-8');
        $safeSnippet = htmlspecialchars($message->snippet, ENT_QUOTES, 'UTF-8');
        $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');

        $body = '<p><strong>' . $safeClient . '</strong> replied about invoice ' . $safeNumber . '.</p>'
            . ($safeSnippet !== ''
                ? '<p style="padding:12px;background:#f5f5f5;border-radius:6px;">' . $safeSnippet . '</p>'
                : '')
            . '<p>Duely has paused the reminders for this invoice, so nothing further will go out '
            . 'until you decide what happens next.</p>'
            . '<p><a href="' . htmlspecialchars($appUrl . '/invoices', ENT_QUOTES, 'UTF-8') . '">Open Duely</a></p>';

        Mailer::send(
            (string) $owner['email'],
            (string) ($owner['name'] ?? ''),
            $clientName . ' replied about ' . $invoiceNumber,
            $body
        );
    }

    private function notifyBounce(int $tenantId, int $chaseId, ?array $invoice, InboundMessage $message): void
    {
        $owner = $this->tenantOwner($tenantId);

        if ($owner === null) {
            return;
        }

        $address = htmlspecialchars((string) ($invoice['client_email'] ?? $message->fromEmail), ENT_QUOTES, 'UTF-8');
        $number = htmlspecialchars((string) ($invoice['number'] ?? 'an invoice'), ENT_QUOTES, 'UTF-8');
        $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');

        $body = '<p>Mail to <strong>' . $address . '</strong> bounced, so Duely has stopped chasing '
            . 'invoice ' . $number . '.</p>'
            . '<p>The address looks wrong or no longer exists. Update it and the reminders can start again.</p>'
            . '<p><a href="' . htmlspecialchars($appUrl . '/clients', ENT_QUOTES, 'UTF-8') . '">Update the client</a></p>';

        Mailer::send(
            (string) $owner['email'],
            (string) ($owner['name'] ?? ''),
            'Reminders for ' . ($invoice['number'] ?? 'an invoice') . ' stopped: the email bounced',
            $body
        );
    }

    // ------------------------------------------------------------ internals

    /**
     * Where this poll should start reading.
     *
     * A changed UIDVALIDITY means the server has renumbered the mailbox and
     * every stored UID now refers to different mail, so the cursor is thrown
     * away rather than trusted.
     */
    private function resolveCursor(array $account, array $mailbox): int
    {
        $storedValidity = $account['imap_uidvalidity'] === null ? null : (int) $account['imap_uidvalidity'];
        $currentValidity = $mailbox['uidvalidity'];

        if ($storedValidity !== null && $currentValidity !== null && $storedValidity !== $currentValidity) {
            // Start from the current end of the mailbox: re-reading months of
            // old mail would be slow and would surface long-dead replies.
            return max(1, ($mailbox['uidnext'] ?? 1) - self::OVERLAP);
        }

        $stored = $account['imap_uidnext'] === null ? null : (int) $account['imap_uidnext'];

        if ($stored === null || $stored < 1) {
            // First ever poll: only look at mail arriving from now on.
            return max(1, ($mailbox['uidnext'] ?? 1) - self::OVERLAP);
        }

        return max(1, $stored - self::OVERLAP);
    }

    private function invoiceForChase(int $tenantId, int $chaseId): ?array
    {
        $chase = Chase::find($tenantId, $chaseId);

        if ($chase === null) {
            return null;
        }

        return Invoice::withClient($tenantId, (int) $chase['invoice_id']);
    }

    private function recordAccountError(int $tenantId, int $accountId, string $message): void
    {
        EmailAccount::update($tenantId, $accountId, [
            'imap_last_error' => mb_substr($message, 0, 1000),
            'imap_last_polled_at' => Clock::toDatabase(Clock::now()),
        ]);
    }

    private function tenantOwner(int $tenantId): ?array
    {
        $statement = \Keel\Core\Database::connection()->prepare(
            'SELECT id, email, name FROM users
             WHERE organization_id = ?
             ORDER BY (role = ?) DESC, id ASC
             LIMIT 1'
        );
        $statement->execute([$tenantId, 'owner']);

        return $statement->fetch() ?: null;
    }
}
