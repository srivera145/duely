<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Jobs\ProcessDueChasesJob;
use Keel\App\Mail\SendResult;
use Keel\App\Models\Chase;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Services\ChaseScheduler;
use Keel\App\Services\ChaseSender;
use Keel\App\Services\Clock;
use Keel\App\Services\SendRateLimiter;
use Keel\App\Services\SequenceSeeder;
use Keel\App\Services\TenantContext;
use Keel\Core\Database;
use PDOException;
use ReflectionClass;
use Tests\Support\RecordingTransport;
use Tests\TestCase;

/**
 * The cadence engine.
 *
 * The invariants worth breaking a build over: one message per due step, never
 * a duplicate after a crash, never a send to a paid invoice, and follow-ups
 * that thread under the first message.
 */
class CadenceEngineFeatureTest extends TestCase
{
    private int $tenantId;
    private int $sequenceId;
    private int $accountId;
    private DateTimeImmutable $now;
    private RecordingTransport $transport;
    private ChaseSender $sender;
    private ChaseScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->tenantId = TenantContext::forUser((int) $user['id']);
        $this->sequenceId = (int) Sequence::defaultSequence($this->tenantId)['id'];

        $this->accountId = EmailAccount::create($this->tenantId, [
            'from_name' => 'Ada Lovelace',
            'from_email' => 'ada@studio.test',
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
            'smtp_username' => 'ada@studio.test',
            'smtp_password' => 'app-password',
            'status' => EmailAccount::STATUS_ACTIVE,
            'is_default' => 1,
        ]);

        // A Wednesday inside the 09:00-16:00 window, so the weekend rule is
        // not silently doing the work in these assertions.
        $this->now = new DateTimeImmutable('2026-08-19 11:00:00', new DateTimeZone('UTC'));

        $this->transport = new RecordingTransport();
        $this->sender = new ChaseSender($this->transport);
        $this->scheduler = new ChaseScheduler();
    }

    // ------------------------------------- self-check: one message, right step

    public function testAnInvoiceEighteenDaysOverdueFiresExactlyOneMessageAtTheFirmStep(): void
    {
        $invoiceId = $this->invoice('INV-1001', 18);

        $start = $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        self::assertSame(2, $start['entry_position'], 'should enter at the day-14 firm step');

        $result = $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame(1, $result['sent']);
        self::assertCount(1, $this->transport->sent);
        self::assertStringContainsString('18 days overdue', $this->transport->sent[0]['subject']);

        $chase = Chase::forInvoice($this->tenantId, $invoiceId);
        $messages = ChaseMessage::forChase($this->tenantId, (int) $chase['id']);

        // The catch-up rule: the missed day-3 step is not also fired.
        self::assertCount(1, $messages);
        self::assertSame(2, (int) $messages[0]['position']);
        self::assertSame(2, (int) $chase['current_position']);
    }

    public function testTheNextStepIsScheduledFromTheDueDateNotFromNow(): void
    {
        $invoiceId = $this->invoice('INV-1001', 18);
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        $this->sender->processDueForTenant($this->tenantId, $this->now);

        $chase = Chase::forInvoice($this->tenantId, $invoiceId);
        $dueDate = new DateTimeImmutable(Invoice::find($this->tenantId, $invoiceId)['due_date']);

        self::assertSame(
            $dueDate->modify('+30 days')->format('Y-m-d'),
            Clock::fromDatabase($chase['next_send_at'])->format('Y-m-d')
        );
    }

    public function testRunningAgainImmediatelySendsNothingMore(): void
    {
        $invoiceId = $this->invoice('INV-1001', 18);
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        $this->sender->processDueForTenant($this->tenantId, $this->now);
        $second = $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame(0, $second['sent']);
        self::assertCount(1, $this->transport->sent);
    }

    public function testAVeryOldInvoiceEntersAtTheFinalStepAndCompletes(): void
    {
        $invoiceId = $this->invoice('INV-1002', 100);
        $start = $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        self::assertSame(3, $start['entry_position']);

        $this->sender->processDueForTenant($this->tenantId, $this->now);
        $chase = Chase::forInvoice($this->tenantId, $invoiceId);

        self::assertSame(1, ChaseMessage::sentCount($this->tenantId, (int) $chase['id']));
        self::assertSame(Chase::STATUS_COMPLETED, $chase['status']);
        self::assertNull($chase['next_send_at']);
    }

    public function testAnInvoiceNotYetDueIsScheduledRatherThanSent(): void
    {
        $invoiceId = $this->invoice('INV-1003', -10, 'future@client.test');
        $start = $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        self::assertSame(1, $start['entry_position']);

        $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame([], $this->transport->to('future@client.test'));
        self::assertSame(Chase::STATUS_SCHEDULED, Chase::forInvoice($this->tenantId, $invoiceId)['status']);
    }

    // ------------------------------------------- self-check: paid never sends

    public function testAChaseOnAPaidInvoiceNeverSends(): void
    {
        $invoiceId = $this->invoice('INV-3001', 18, 'paid@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        // Paid after scheduling, before the worker runs: the dangerous window.
        Invoice::markPaid($this->tenantId, $invoiceId);

        $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame([], $this->transport->to('paid@client.test'));

        $chase = Chase::forInvoice($this->tenantId, $invoiceId);
        self::assertSame(Chase::STATUS_PAUSED, $chase['status']);
        self::assertSame(Chase::PAUSE_INVOICE_PAID, $chase['paused_reason']);
        self::assertSame([], ChaseMessage::forChase($this->tenantId, (int) $chase['id']), 'a message row was staged for a paid invoice');
    }

    public function testASuppressedClientIsNeverEmailed(): void
    {
        $invoiceId = $this->invoice('INV-3002', 18, 'suppressed@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        Client::update($this->tenantId, (int) Invoice::find($this->tenantId, $invoiceId)['client_id'], [
            'suppressed_at' => Clock::toDatabase($this->now),
            'suppressed_reason' => 'complained',
        ]);

        $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame([], $this->transport->to('suppressed@client.test'));
        self::assertSame(Chase::STATUS_STOPPED, Chase::forInvoice($this->tenantId, $invoiceId)['status']);
    }

    public function testABrokenMailboxSendsNothing(): void
    {
        $invoiceId = $this->invoice('INV-3003', 18, 'reauth@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        EmailAccount::update($this->tenantId, $this->accountId, ['status' => EmailAccount::STATUS_NEEDS_REAUTH]);

        $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame([], $this->transport->to('reauth@client.test'));
        self::assertSame(Chase::PAUSE_NEEDS_REAUTH, Chase::forInvoice($this->tenantId, $invoiceId)['paused_reason']);
    }

    public function testAVoidedInvoiceSendsNothing(): void
    {
        $invoiceId = $this->invoice('INV-3004', 18, 'void@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        Invoice::markVoid($this->tenantId, $invoiceId);
        $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame([], $this->transport->to('void@client.test'));
    }

    // ------------------------------------------------ self-check: threading

    public function testFollowUpsThreadUnderTheFirstMessage(): void
    {
        $invoiceId = $this->invoice('INV-2001', 3, 'thread@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        $this->sender->processDueForTenant($this->tenantId, $this->now);

        $thread = $this->transport->to('thread@client.test');
        self::assertCount(1, $thread);

        $first = $thread[0];
        self::assertStringStartsWith('<', $first['headers']['Message-ID']);
        self::assertArrayNotHasKey('In-Reply-To', $first['headers'], 'the first message must start the thread');

        $chase = Chase::forInvoice($this->tenantId, $invoiceId);
        $rootId = $chase['root_message_id'];
        self::assertSame($first['message_id'], $rootId, 'the root Message-ID was not captured');

        // Move to when step 2 is due and send it.
        $this->sender->processDueForTenant($this->tenantId, $this->weekdayInWindow($chase['next_send_at']));

        $thread = $this->transport->to('thread@client.test');
        self::assertCount(2, $thread);

        $second = $thread[1];
        self::assertNotSame($rootId, $second['message_id'], 'each send needs its own Message-ID');
        self::assertSame($rootId, $second['headers']['In-Reply-To']);
        self::assertStringContainsString($rootId, $second['headers']['References']);
        self::assertSame(1, substr_count($second['headers']['References'], $rootId));

        // RFC 5322: space-separated angle-addr list.
        self::assertMatchesRegularExpression(
            '/^(<[^>]+>)( <[^>]+>)*$/',
            $second['headers']['References']
        );
    }

    // ------------------------------------------- self-check: crash safety

    public function testAnInterruptedSendIsNeverResent(): void
    {
        $invoiceId = $this->invoice('INV-4001', 18, 'crash@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        $chaseId = (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'];

        // Simulate a process death after the message reached the transport:
        // dispatched_at is stamped, but no outcome was ever recorded.
        $dying = new RecordingTransport();
        $dying->onSend = static function (): void {
            throw new \RuntimeException('worker killed mid-send');
        };

        try {
            (new ChaseSender($dying))->processDueForTenant($this->tenantId, $this->now);
        } catch (\Throwable) {
            // The crash.
        }

        // Force the state a real kill leaves behind: queued, dispatched, no outcome.
        $messages = ChaseMessage::forChase($this->tenantId, $chaseId);
        self::assertCount(1, $messages);
        ChaseMessage::update($this->tenantId, (int) $messages[0]['id'], [
            'status' => ChaseMessage::STATUS_QUEUED,
            'dispatched_at' => Clock::toDatabase($this->now),
            'next_attempt_at' => null,
        ]);
        Chase::update($this->tenantId, $chaseId, ['next_send_at' => null]);

        // Restart, past the interrupted-send threshold.
        $restart = new RecordingTransport();
        (new ChaseSender($restart))->processDueForTenant($this->tenantId, $this->now->modify('+20 minutes'));

        self::assertSame([], $restart->to('crash@client.test'), 'the interrupted message was resent');

        $messages = ChaseMessage::forChase($this->tenantId, $chaseId);
        self::assertCount(1, $messages, 'a duplicate message row was created');
        self::assertSame(ChaseMessage::STATUS_FAILED, $messages[0]['status']);
        self::assertStringContainsString('twice', (string) $messages[0]['failed_reason']);
    }

    public function testTheUniqueIndexMakesADuplicateRowImpossible(): void
    {
        $invoiceId = $this->invoice('INV-4003', 18);
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        $this->sender->processDueForTenant($this->tenantId, $this->now);

        $chaseId = (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'];
        $existing = ChaseMessage::forChase($this->tenantId, $chaseId)[0];

        $this->expectException(PDOException::class);

        ChaseMessage::create($this->tenantId, [
            'chase_id' => $chaseId,
            'position' => (int) $existing['position'],
            'to_email' => 'x@test.test',
            'from_email' => 'ada@studio.test',
            'subject' => 'duplicate',
            'body_text' => 'duplicate',
            'rfc_message_id' => ChaseMessage::newMessageId(),
        ]);
    }

    public function testAMessageThatNeverReachedTheTransportIsRetried(): void
    {
        $invoiceId = $this->invoice('INV-4002', 18, 'retry@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        $chaseId = (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'];

        $dying = new RecordingTransport();
        $dying->onSend = static function (): void {
            throw new \RuntimeException('killed');
        };

        try {
            (new ChaseSender($dying))->processDueForTenant($this->tenantId, $this->now);
        } catch (\Throwable) {
        }

        // Nothing left the building this time.
        $messages = ChaseMessage::forChase($this->tenantId, $chaseId);
        ChaseMessage::update($this->tenantId, (int) $messages[0]['id'], [
            'status' => ChaseMessage::STATUS_QUEUED,
            'dispatched_at' => null,
            'next_attempt_at' => null,
        ]);
        Chase::update($this->tenantId, $chaseId, ['next_send_at' => null]);

        $restart = new RecordingTransport();
        (new ChaseSender($restart))->processDueForTenant($this->tenantId, $this->now->modify('+20 minutes'));

        self::assertCount(1, $restart->to('retry@client.test'), 'an undispatched message should be retried');
        self::assertCount(1, ChaseMessage::forChase($this->tenantId, $chaseId));
    }

    // ---------------------------------------------- send window and timezone

    public function testTheSendWindowShiftsTimesForwardAndSkipsWeekends(): void
    {
        $sequence = Sequence::find($this->tenantId, $this->sequenceId);
        $utc = new DateTimeZone('UTC');

        $cases = [
            // Saturday and Sunday both land on Monday morning.
            ['2026-08-22 11:00:00', '2026-08-24 09:00'],
            ['2026-08-23 11:00:00', '2026-08-24 09:00'],
            // Before the window opens, and after it closes.
            ['2026-08-19 03:00:00', '2026-08-19 09:00'],
            ['2026-08-19 22:00:00', '2026-08-20 09:00'],
            // Already inside: untouched.
            ['2026-08-19 11:30:00', '2026-08-19 11:30'],
        ];

        foreach ($cases as [$input, $expected]) {
            $shifted = $this->scheduler->shiftIntoWindow(new DateTimeImmutable($input, $utc), $sequence, $utc);
            self::assertSame($expected, $shifted->format('Y-m-d H:i'), 'input ' . $input);
        }
    }

    public function testTheWindowIsInterpretedInTheClientTimezone(): void
    {
        $sequence = Sequence::find($this->tenantId, $this->sequenceId);
        $step = \Keel\App\Models\SequenceStep::atPosition($this->tenantId, $this->sequenceId, 1);

        foreach (['Asia/Tokyo', 'America/Los_Angeles', 'Europe/Berlin'] as $timezone) {
            $sendAt = $this->scheduler->sendAtFor(
                $step,
                ['due_date' => '2026-08-19', 'client_timezone' => $timezone],
                $sequence
            );

            self::assertSame('UTC', $sendAt->getTimezone()->getName(), 'stored time must be UTC');
            self::assertSame(
                '09:00',
                $sendAt->setTimezone(new DateTimeZone($timezone))->format('H:i'),
                '9am should mean 9am in ' . $timezone
            );
        }
    }

    public function testAnInvalidClientTimezoneFallsBackToUtcRatherThanThrowing(): void
    {
        self::assertSame('UTC', $this->scheduler->timezoneFor(['client_timezone' => 'Not/AZone'])->getName());
    }

    public function testABacklogClearedAtNightDoesNotEmailEveryoneAtThreeAm(): void
    {
        $invoiceId = $this->invoice('INV-5001', 18, 'night@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        $threeAm = new DateTimeImmutable('2026-08-20 03:00:00', new DateTimeZone('UTC'));
        $this->sender->processDueForTenant($this->tenantId, $threeAm);

        self::assertSame([], $this->transport->to('night@client.test'));

        $rescheduled = Clock::fromDatabase(Chase::forInvoice($this->tenantId, $invoiceId)['next_send_at']);
        self::assertSame('09:00', $rescheduled->format('H:i'));
    }

    // ------------------------------------------------------- rate limiting

    public function testJitterStaysInsideTheConfiguredRange(): void
    {
        $limiter = new SendRateLimiter();

        for ($i = 0; $i < 100; $i++) {
            $gap = $limiter->jitterSeconds();
            self::assertGreaterThanOrEqual(SendRateLimiter::MIN_GAP_SECONDS, $gap);
            self::assertLessThanOrEqual(SendRateLimiter::MAX_GAP_SECONDS, $gap);
        }
    }

    public function testAnHourlyCapBlocksFurtherSendsAndSchedulesARetry(): void
    {
        $invoiceId = $this->invoice('INV-6001', 18, 'limited@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        $chaseId = (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'];

        $this->backfillSends($chaseId, SendRateLimiter::MAX_PER_HOUR, $this->now->modify('-10 minutes'));

        $limiter = new SendRateLimiter();
        $allowance = $limiter->check($this->tenantId, $this->accountId, $this->now);

        self::assertFalse($allowance['allowed']);
        self::assertGreaterThan($this->now, $allowance['retry_after'], 'retry_after must be in the future');

        $this->sender->processDueForTenant($this->tenantId, $this->now);

        self::assertSame([], $this->transport->to('limited@client.test'));
        self::assertNotNull(
            Chase::forInvoice($this->tenantId, $invoiceId)['next_send_at'],
            'a rate-limited chase must be rescheduled, not dropped'
        );
    }

    // -------------------------------------------------------------- retries

    public function testTransientFailuresRetryThenGiveUpAndFlagTheMailbox(): void
    {
        $invoiceId = $this->invoice('INV-7001', 18, 'flaky@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);
        $chaseId = (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'];

        $flaky = new RecordingTransport();
        $sender = new ChaseSender($flaky);
        $at = $this->now;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $flaky->nextResult = SendResult::transientFailure('The mail server is busy.');
            $sender->processDueForTenant($this->tenantId, $at);

            $messages = ChaseMessage::forChase($this->tenantId, $chaseId);
            self::assertCount(1, $messages, 'retries must reuse one message row');
            $message = $messages[0];

            if ($attempt < 3) {
                self::assertSame(ChaseMessage::STATUS_QUEUED, $message['status']);
                self::assertSame($attempt, (int) $message['attempts']);
                self::assertNull($message['dispatched_at'], 'a scheduled retry must clear dispatched_at');

                $at = $this->weekdayInWindow($message['next_attempt_at']);
            }
        }

        $message = ChaseMessage::forChase($this->tenantId, $chaseId)[0];
        self::assertSame(ChaseMessage::STATUS_FAILED, $message['status']);
        self::assertSame(3, (int) $message['attempts']);

        self::assertSame(
            EmailAccount::STATUS_NEEDS_REAUTH,
            EmailAccount::find($this->tenantId, $this->accountId)['status']
        );
        self::assertSame(Chase::PAUSE_NEEDS_REAUTH, Chase::forInvoice($this->tenantId, $invoiceId)['paused_reason']);
    }

    public function testAnAuthFailureDoesNotBurnThreeAttempts(): void
    {
        $invoiceId = $this->invoice('INV-7002', 18, 'auth@client.test');
        $this->scheduler->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        $transport = new RecordingTransport();
        $transport->nextResult = SendResult::authFailure('The server rejected that username and password.');

        (new ChaseSender($transport))->processDueForTenant($this->tenantId, $this->now);

        $chaseId = (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'];
        $message = ChaseMessage::forChase($this->tenantId, $chaseId)[0];

        self::assertSame(ChaseMessage::STATUS_FAILED, $message['status']);
        self::assertSame(1, (int) $message['attempts'], 'a wrong password should not be retried');
    }

    // ------------------------------------------------ transport abstraction

    public function testTheSchedulerAndSenderKnowNothingAboutSmtp(): void
    {
        $schedulerSource = (string) file_get_contents((new ReflectionClass(ChaseScheduler::class))->getFileName());
        self::assertStringNotContainsString('PHPMailer', $schedulerSource);
        self::assertStringNotContainsString('Transport', $schedulerSource);

        $senderSource = (string) file_get_contents((new ReflectionClass(ChaseSender::class))->getFileName());
        self::assertStringNotContainsString('PHPMailer', $senderSource);
        self::assertStringNotContainsString('smtp_host', $senderSource);
    }

    // --------------------------------------------------------- job fan-out

    public function testTheJobProcessesEachTenantInIsolation(): void
    {
        $mine = $this->invoice('INV-8001', 18, 'mine@client.test');
        $this->scheduler->start($this->tenantId, $mine, $this->sequenceId, $this->accountId, $this->now);

        $other = $this->createUser(['email' => 'bo@studio.test', 'name' => 'Bo']);
        $otherTenant = TenantContext::forUser((int) $other['id']);
        SequenceSeeder::seed($otherTenant);

        $otherAccount = EmailAccount::create($otherTenant, [
            'from_name' => 'Bo', 'from_email' => 'bo@studio.test', 'smtp_host' => 'smtp.test',
            'smtp_port' => 587, 'smtp_username' => 'bo@studio.test', 'smtp_password' => 'pw',
            'status' => EmailAccount::STATUS_ACTIVE, 'is_default' => 1,
        ]);
        $theirs = $this->invoice('B-1', 18, 'theirs@client.test', $otherTenant);
        $this->scheduler->start($otherTenant, $theirs, (int) Sequence::defaultSequence($otherTenant)['id'], $otherAccount, $this->now);

        $transport = new RecordingTransport();
        $totals = (new ProcessDueChasesJob(new ChaseSender($transport)))->run(null, $this->now);

        self::assertSame(2, $totals['tenants']);
        self::assertSame(2, $totals['sent']);

        // Each message went out from its own tenant's mailbox.
        self::assertSame('ada@studio.test', $transport->to('mine@client.test')[0]['from']);
        self::assertSame('bo@studio.test', $transport->to('theirs@client.test')[0]['from']);
    }

    // -------------------------------------------------------------- helpers

    private function invoice(string $number, int $daysOverdue, string $email = 'dana@client.test', ?int $tenantId = null): int
    {
        $tenantId ??= $this->tenantId;

        $clientId = Client::findOrCreate($tenantId, $email, [
            'name' => 'Dana Whitfield',
            'company' => 'Whitfield & Partners',
            'timezone' => 'UTC',
        ]);

        // A negative value means "not due yet"; the sign is built explicitly
        // because modify('--10 days') is not a negation.
        $dueDate = $this->now
            ->modify(($daysOverdue >= 0 ? '-' : '+') . abs($daysOverdue) . ' days')
            ->format('Y-m-d');

        return Invoice::create($tenantId, [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => $dueDate,
            'payment_url' => 'https://pay.example.com/' . strtolower($number),
        ]);
    }

    /**
     * Read a stored datetime and nudge it to a weekday inside the send window,
     * so a test asserting on a retry is not defeated by the window rule.
     */
    private function weekdayInWindow(?string $stored): DateTimeImmutable
    {
        $moment = Clock::fromDatabase($stored) ?? $this->now;

        if ((int) $moment->format('H') < 9 || (int) $moment->format('H') > 15) {
            $moment = $moment->setTime(11, 0);
        }

        while ((int) $moment->format('N') >= 6) {
            $moment = $moment->modify('+1 day')->setTime(11, 0);
        }

        return $moment;
    }

    private function backfillSends(int $chaseId, int $count, DateTimeImmutable $sentAt): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO chase_messages
                (tenant_id, chase_id, email_account_id, position, to_email, from_email,
                 subject, body_text, rfc_message_id, status, sent_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );

        for ($i = 0; $i < $count; $i++) {
            $statement->execute([
                $this->tenantId,
                $chaseId,
                $this->accountId,
                500 + $i,
                'x@test.test',
                'ada@studio.test',
                'backfill',
                'backfill',
                '<backfill' . $i . '@duely.app>',
                ChaseMessage::STATUS_SENT,
                Clock::toDatabase($sentAt),
            ]);
        }
    }
}
