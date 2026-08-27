<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Models\Chase;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\ReplyEvent;
use Keel\App\Models\Sequence;
use Keel\App\Models\SequenceStep;
use Keel\App\Services\ChaseScheduler;
use Keel\App\Services\ChaseSender;
use Keel\App\Services\Clock;
use Keel\App\Services\DashboardMetrics;
use Keel\App\Services\InvoiceTimeline;
use Keel\App\Services\RelativeTime;
use Keel\App\Services\SequenceSeeder;
use Keel\App\Services\TenantContext;
use Keel\App\Services\UndoService;
use Keel\Core\Database;
use Tests\Support\RecordingTransport;
use Tests\TestCase;

/**
 * The dashboard, the invoice timeline, and the manual controls.
 *
 * The performance assertion here is deliberately part of the suite rather than
 * a one-off measurement: an N+1 introduced later is exactly the kind of change
 * that looks harmless in review and makes the main screen unusable.
 */
class DashboardFeatureTest extends TestCase
{
    private int $tenantId;
    private int $sequenceId;
    private int $accountId;
    private array $user;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->tenantId = TenantContext::forUser((int) $this->user['id']);
        $this->sequenceId = (int) Sequence::defaultSequence($this->tenantId)['id'];
        $this->now = new DateTimeImmutable('2026-08-19 11:00:00', new DateTimeZone('UTC'));

        $this->accountId = EmailAccount::create($this->tenantId, [
            'from_name' => 'Ada Lovelace', 'from_email' => 'ada@studio.test',
            'smtp_host' => 'smtp.test', 'smtp_port' => 587,
            'smtp_username' => 'ada@studio.test', 'smtp_password' => 'pw',
            'status' => EmailAccount::STATUS_ACTIVE, 'is_default' => 1,
        ]);
    }

    // ------------------------------------- self-check: performance at scale

    public function testTheDashboardStaysFastWithFiveHundredInvoices(): void
    {
        $this->seedAtScale(500, 150);

        $metrics = new DashboardMetrics();

        // Warm up, so this measures steady state rather than a cold buffer pool.
        $metrics->cards($this->tenantId, $this->now);
        $metrics->activeChases($this->tenantId, $this->now);

        $runs = [];
        for ($i = 0; $i < 5; $i++) {
            $start = microtime(true);
            $metrics->cards($this->tenantId, $this->now);
            $metrics->activeChases($this->tenantId, $this->now);
            $metrics->needsAttention($this->tenantId);
            $runs[] = (microtime(true) - $start) * 1000;
        }

        sort($runs);
        $median = $runs[2];

        self::assertLessThan(
            300,
            $median,
            'the dashboard took ' . number_format($median, 1) . 'ms with 500 invoices'
        );
    }

    public function testTheDashboardIssuesAFixedNumberOfQueriesRegardlessOfRowCount(): void
    {
        $this->seedAtScale(200, 120);

        $metrics = new DashboardMetrics();

        // Four cards, four aggregate queries.
        $before = $this->queryCount();
        $metrics->cards($this->tenantId, $this->now);
        self::assertSame(4, $this->queryCount() - $before - 1, 'the cards are not one query each');

        // One join for the whole table — not one lookup per chase.
        $before = $this->queryCount();
        $rows = $metrics->activeChases($this->tenantId, $this->now);
        self::assertSame(1, $this->queryCount() - $before - 1, 'the active chases table has an N+1');
        self::assertGreaterThan(50, count($rows));
    }

    // ------------------------------------------------------ card correctness

    public function testTheCardsReportWhatTheDatabaseActuallyHolds(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        // Two open (one overdue), one paid recently, one paid long ago.
        $this->invoice($clientId, 'OPEN-OVERDUE', 100000, -10);
        $this->invoice($clientId, 'OPEN-FUTURE', 50000, 10);
        $this->invoice($clientId, 'PAID-RECENT', 70000, -20, 'paid', -5);
        $this->invoice($clientId, 'PAID-OLD', 90000, -200, 'paid', -120);

        $cards = (new DashboardMetrics())->cards($this->tenantId, $this->now);

        self::assertSame(150000, $cards['outstanding_total'], 'outstanding should only count open invoices');
        self::assertSame(1, $cards['overdue_count']);
        self::assertSame(100000, $cards['overdue_cents']);

        // Only the last 30 days counts as recovered.
        self::assertSame(70000, $cards['recovered_cents']);
        self::assertSame(1, $cards['recovered_count']);

        self::assertNotNull($cards['average_days_to_payment']);
    }

    public function testATenantWithNoPaymentHistoryShowsNoAverageRatherThanZero(): void
    {
        $cards = (new DashboardMetrics())->cards($this->tenantId, $this->now);

        self::assertNull($cards['average_days_to_payment']);
        self::assertSame(0, $cards['paid_sample']);
    }

    public function testOutstandingIsSplitByCurrencyRatherThanSummedAcrossThem(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        $this->invoice($clientId, 'USD-1', 100000, -5);
        $this->invoice($clientId, 'EUR-1', 200000, -5, 'open', null, 'EUR');

        $cards = (new DashboardMetrics())->cards($this->tenantId, $this->now);

        self::assertArrayHasKey('USD', $cards['outstanding']);
        self::assertArrayHasKey('EUR', $cards['outstanding']);
        self::assertSame(100000, $cards['outstanding']['USD']);
        self::assertSame(200000, $cards['outstanding']['EUR']);
    }

    // ----------------------------------------------------------- relative time

    public function testTimesRenderAsPhrasesNotTimestamps(): void
    {
        self::assertSame('in 2 days', RelativeTime::phrase($this->now->modify('+2 days'), $this->now));
        self::assertSame('in 1 day', RelativeTime::phrase($this->now->modify('+1 day'), $this->now));
        self::assertSame('3 hours ago', RelativeTime::phrase($this->now->modify('-3 hours'), $this->now));
        self::assertSame('in a moment', RelativeTime::phrase($this->now->modify('+10 seconds'), $this->now));
        self::assertNull(RelativeTime::phrase(null, $this->now));
    }

    public function testTimesCanBeRenderedInTheViewerTimezone(): void
    {
        // 11:00 UTC is 20:00 in Tokyo.
        self::assertStringContainsString('20:00', (string) RelativeTime::inTimezone($this->now, 'Asia/Tokyo'));
        self::assertNotNull(RelativeTime::inTimezone($this->now, 'Not/AZone'), 'a bad timezone must not throw');
    }

    // ------------------------------------ self-check: mark paid stops a chase

    public function testMarkingPaidStopsAnInFlightChase(): void
    {
        $chase = $this->liveChase('INV-LIVE', 'dana@whitfield.test');

        $this->signIn();
        $tenantId = $this->tenantId;

        $response = $this->postJson('/api/invoices/' . $chase['invoice_id'] . '/mark-paid', [
            '_csrf' => $this->csrfToken(),
        ]);

        self::assertSame(200, $response->status, $response->body);

        $after = Chase::find($tenantId, $chase['chase_id']);
        self::assertSame(Chase::STATUS_PAUSED, $after['status']);
        self::assertSame(Chase::PAUSE_INVOICE_PAID, $after['paused_reason']);
        self::assertNull($after['next_send_at']);

        $invoice = Invoice::find($tenantId, $chase['invoice_id']);
        self::assertSame(Invoice::STATUS_PAID, $invoice['status']);
        self::assertSame('manual', $invoice['paid_source']);
        self::assertNotNull($invoice['paid_at']);

        // And the engine really will not send again.
        $transport = new RecordingTransport();
        (new ChaseSender($transport))->processDueForTenant($tenantId, $this->now->modify('+40 days'));
        self::assertSame([], $transport->to('dana@whitfield.test'));
    }

    public function testMarkPaidIsUndoableAndRestoresTheExactPriorState(): void
    {
        $chase = $this->liveChase('INV-LIVE', 'dana@whitfield.test');
        $before = Chase::find($this->tenantId, $chase['chase_id']);

        $this->signIn();

        $body = json_decode($this->postJson('/api/invoices/' . $chase['invoice_id'] . '/mark-paid', [
            '_csrf' => $this->csrfToken(),
        ])->body, true);

        self::assertNotEmpty($body['undo_token']);
        self::assertSame(UndoService::WINDOW_SECONDS, $body['undo_expires_in']);

        $undone = $this->postJson('/api/invoices/undo', [
            '_csrf' => $this->csrfToken(),
            'undo_token' => $body['undo_token'],
        ]);

        self::assertSame(200, $undone->status, $undone->body);

        $invoice = Invoice::find($this->tenantId, $chase['invoice_id']);
        self::assertSame(Invoice::STATUS_OPEN, $invoice['status']);
        self::assertNull($invoice['paid_at']);
        self::assertNull($invoice['paid_source']);

        $after = Chase::find($this->tenantId, $chase['chase_id']);
        self::assertSame($before['status'], $after['status']);
        self::assertSame($before['next_send_at'], $after['next_send_at'], 'the schedule was not restored exactly');
        self::assertSame($before['current_position'], $after['current_position']);
    }

    public function testAnUndoTokenIsSingleUseAndExpires(): void
    {
        $chase = $this->liveChase('INV-LIVE', 'dana@whitfield.test');
        $undo = new UndoService();

        $snapshot = [
            'invoice' => ['status' => 'open', 'paid_at' => null, 'paid_source' => null],
            'chase' => null,
        ];

        $token = $undo->remember(
            $this->tenantId, UndoService::ACTION_MARK_PAID, 'Invoice',
            $chase['invoice_id'], $snapshot, $this->now
        );

        self::assertTrue($undo->undo($this->tenantId, $token['token'], $this->now)['undone']);

        $second = $undo->undo($this->tenantId, $token['token'], $this->now);
        self::assertFalse($second['undone']);
        self::assertStringContainsString('already', (string) $second['reason']);

        $stale = $undo->remember(
            $this->tenantId, UndoService::ACTION_MARK_PAID, 'Invoice',
            $chase['invoice_id'], $snapshot, $this->now
        );
        $expired = $undo->undo($this->tenantId, $stale['token'], $this->now->modify('+31 seconds'));

        self::assertFalse($expired['undone']);
        self::assertStringContainsString('window', (string) $expired['reason']);
    }

    // ------------------------------- send now: window yes, hard stops never

    public function testSendNowBypassesTheSendWindow(): void
    {
        $chase = $this->liveChase('INV-NOW', 'night@client.test', false);
        $threeAm = new DateTimeImmutable('2026-08-20 03:00:00', new DateTimeZone('UTC'));

        Chase::update($this->tenantId, $chase['chase_id'], [
            'next_send_at' => Clock::toDatabase($threeAm->modify('-1 second')),
        ]);

        $transport = new RecordingTransport();
        $sender = new ChaseSender($transport);

        // The ordinary path refuses at 3am.
        $sender->processNext($this->tenantId, $threeAm);
        self::assertSame([], $transport->to('night@client.test'));

        // A deliberate send-now does not.
        Chase::update($this->tenantId, $chase['chase_id'], [
            'next_send_at' => Clock::toDatabase($threeAm->modify('-1 second')),
        ]);
        $outcome = $sender->processNext($this->tenantId, $threeAm, true);

        self::assertSame('sent', $outcome['outcome']);
        self::assertCount(1, $transport->to('night@client.test'));
    }

    public function testSendNowStillRefusesToEmailAPaidInvoice(): void
    {
        $chase = $this->liveChase('INV-PAID', 'paid@client.test', false);
        Invoice::markPaid($this->tenantId, $chase['invoice_id']);

        Chase::update($this->tenantId, $chase['chase_id'], [
            'next_send_at' => Clock::toDatabase($this->now->modify('-1 second')),
        ]);

        $transport = new RecordingTransport();
        $outcome = (new ChaseSender($transport))->processNext($this->tenantId, $this->now, true);

        self::assertSame([], $transport->to('paid@client.test'), 'send-now emailed a paid invoice');
        self::assertSame('cancelled', $outcome['outcome']);
    }

    public function testSendNowStillRefusesToEmailASuppressedClient(): void
    {
        $chase = $this->liveChase('INV-SUP', 'suppressed@client.test', false);

        Client::update($this->tenantId, $chase['client_id'], [
            'suppressed_at' => Clock::toDatabase($this->now),
        ]);
        Chase::update($this->tenantId, $chase['chase_id'], [
            'next_send_at' => Clock::toDatabase($this->now->modify('-1 second')),
        ]);

        $transport = new RecordingTransport();
        (new ChaseSender($transport))->processNext($this->tenantId, $this->now, true);

        self::assertSame([], $transport->to('suppressed@client.test'));
    }

    // ------------------------------------------------------------- timeline

    public function testTheTimelineTellsTheWholeStoryInOrder(): void
    {
        $chase = $this->liveChase('INV-STORY', 'dana@whitfield.test');

        ReplyEvent::record($this->tenantId, [
            'chase_id' => $chase['chase_id'],
            'email_account_id' => $this->accountId,
            'provider_message_id' => $this->accountId . ':4242',
            'provider_uid' => 4242,
            'type' => ReplyEvent::TYPE_REPLY,
            'from_email' => 'dana@whitfield.test',
            'subject' => 'Re: reminder',
            'snippet' => 'Sorry, paying on Friday.',
            'rfc_message_id' => '<story@whitfield.test>',
            'received_at' => Clock::toDatabase($this->now->modify('-1 hour')),
        ]);

        $timeline = (new InvoiceTimeline())->build($this->tenantId, $chase['invoice_id'], $this->now);

        self::assertNotNull($timeline);

        $types = array_column($timeline['events'], 'type');
        self::assertContains('created', $types);
        self::assertContains('chase_started', $types);
        self::assertContains('message', $types);
        self::assertContains('reply', $types);

        $times = array_column($timeline['events'], 'at');
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times, 'the timeline is not chronological');
    }

    public function testASentReminderCarriesItsFullBodyButAReplyOnlyItsSnippet(): void
    {
        $chase = $this->liveChase('INV-BODY', 'dana@whitfield.test');

        ReplyEvent::record($this->tenantId, [
            'chase_id' => $chase['chase_id'],
            'email_account_id' => $this->accountId,
            'provider_message_id' => $this->accountId . ':4343',
            'provider_uid' => 4343,
            'type' => ReplyEvent::TYPE_REPLY,
            'from_email' => 'dana@whitfield.test',
            'snippet' => 'Paying Friday.',
            'rfc_message_id' => '<body@whitfield.test>',
            'received_at' => Clock::toDatabase($this->now),
        ]);

        $timeline = (new InvoiceTimeline())->build($this->tenantId, $chase['invoice_id'], $this->now);

        $message = null;
        $reply = null;
        foreach ($timeline['events'] as $event) {
            if ($event['type'] === 'message') {
                $message = $event;
            }
            if ($event['type'] === 'reply') {
                $reply = $event;
            }
        }

        // We sent it, so we have every word of it.
        self::assertNotEmpty($message['body_text']);
        self::assertStringContainsString('Thanks,', $message['body_text']);

        // We only ever kept a snippet of theirs.
        self::assertSame('Paying Friday.', $reply['snippet']);
        self::assertArrayNotHasKey('body_text', $reply);
    }

    public function testTheProgressRailFollowsTheTenantsOwnLadder(): void
    {
        $chase = $this->liveChase('INV-RAIL', 'dana@whitfield.test');

        $timeline = (new InvoiceTimeline())->build($this->tenantId, $chase['invoice_id'], $this->now);
        $rail = $timeline['rail'];

        self::assertCount(3, $rail);
        self::assertSame([3, 14, 30], array_column($rail, 'offset_days'));
        self::assertSame(['Day 3', 'Day 14', 'Day 30'], array_column($rail, 'label'));
        self::assertSame(['polite', 'firm', 'final'], array_column($rail, 'tone'));
        self::assertContains('sent', array_column($rail, 'state'));
    }

    public function testAnEditedLadderProducesAnEditedRail(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);
        $invoiceId = $this->invoice($clientId, 'INV-CUSTOM', 100000, -20);

        $sequenceId = Sequence::createWithSteps($this->tenantId, ['name' => 'Custom'], [
            ['offset_days' => -2, 'subject_template' => 'S', 'body_template' => 'B'],
            ['offset_days' => 7, 'subject_template' => 'S', 'body_template' => 'B'],
        ]);

        $rail = (new InvoiceTimeline())->rail(
            Invoice::withClient($this->tenantId, $invoiceId),
            SequenceStep::forSequence($this->tenantId, $sequenceId),
            [],
            $this->now
        );

        self::assertSame(['2 days before', 'Day 7'], array_column($rail, 'label'));
    }

    // ----------------------------------- self-check: cross-tenant isolation

    public function testNoActionOnOneTenantsInvoiceIsReachableFromAnother(): void
    {
        // Tenant A sets up a live chase.
        $owner = $this->createUser(['email' => 'owner@studio.test', 'name' => 'Owner']);
        $ownerTenant = TenantContext::forUser((int) $owner['id']);
        $ownerAccount = EmailAccount::create($ownerTenant, [
            'from_name' => 'Owner', 'from_email' => 'owner@studio.test',
            'smtp_host' => 'smtp.test', 'smtp_port' => 587,
            'smtp_username' => 'owner@studio.test', 'smtp_password' => 'pw',
            'status' => EmailAccount::STATUS_ACTIVE, 'is_default' => 1,
        ]);
        $ownerClient = Client::create($ownerTenant, ['name' => 'Their Client', 'email' => 'theirs@client.test']);
        $ownerInvoice = Invoice::create($ownerTenant, [
            'client_id' => $ownerClient, 'number' => 'THEIRS-1', 'amount_cents' => 500000,
            'currency' => 'USD', 'due_date' => $this->now->modify('-18 days')->format('Y-m-d'),
        ]);
        (new ChaseScheduler())->start(
            $ownerTenant, $ownerInvoice,
            (int) Sequence::defaultSequence($ownerTenant)['id'], $ownerAccount, $this->now
        );
        $ownerChase = (int) Chase::forInvoice($ownerTenant, $ownerInvoice)['id'];

        // Tenant B signs in and tries every action.
        $this->actingAs(['email' => 'mallory@rival.test', 'name' => 'Mallory']);

        foreach (['pause', 'resume', 'stop', 'send-now'] as $action) {
            $response = $this->postJson('/api/chases/' . $ownerChase . '/' . $action, [
                '_csrf' => $this->csrfToken(),
            ]);

            self::assertSame(404, $response->status, $action . ' reached another tenant chase');
        }

        $marked = $this->postJson('/api/invoices/' . $ownerInvoice . '/mark-paid', ['_csrf' => $this->csrfToken()]);
        self::assertSame(404, $marked->status);

        $timeline = $this->get('/invoices/' . $ownerInvoice);
        self::assertSame(302, $timeline->status, 'another tenant invoice timeline was rendered');

        // Nothing changed.
        self::assertSame(Invoice::STATUS_OPEN, Invoice::find($ownerTenant, $ownerInvoice)['status']);
        self::assertNotSame(Chase::STATUS_PAUSED, Chase::find($ownerTenant, $ownerChase)['status']);
    }

    // -------------------------------------------------------------- routes

    public function testTheDashboardRequiresAuthenticationAndRenders(): void
    {
        self::assertSame(302, $this->get('/dashboard')->status);

        $this->liveChase('INV-RENDER', 'dana@whitfield.test');
        $this->signIn();

        $response = $this->get('/dashboard');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Outstanding', $response->body);
        self::assertStringContainsString('Being chased', $response->body);
    }

    public function testEveryStateChangingEndpointRequiresCsrf(): void
    {
        $chase = $this->liveChase('INV-CSRF', 'dana@whitfield.test');
        $this->signIn();

        $paths = [
            '/api/chases/' . $chase['chase_id'] . '/pause',
            '/api/chases/' . $chase['chase_id'] . '/resume',
            '/api/chases/' . $chase['chase_id'] . '/stop',
            '/api/chases/' . $chase['chase_id'] . '/send-now',
            '/api/invoices/' . $chase['invoice_id'] . '/mark-paid',
            '/api/invoices/undo',
        ];

        foreach ($paths as $path) {
            self::assertSame(419, $this->post($path, [])->status, $path . ' is not CSRF protected');
        }
    }

    public function testEveryStateChangeReachesTheAuditLog(): void
    {
        $chase = $this->liveChase('INV-AUDIT', 'dana@whitfield.test');
        $this->signIn();

        $this->postJson('/api/chases/' . $chase['chase_id'] . '/pause', ['_csrf' => $this->csrfToken()]);
        $this->postJson('/api/chases/' . $chase['chase_id'] . '/resume', ['_csrf' => $this->csrfToken()]);
        $this->postJson('/api/invoices/' . $chase['invoice_id'] . '/mark-paid', ['_csrf' => $this->csrfToken()]);

        $actions = Database::connection()
            ->query('SELECT action FROM activity_log')
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('chase.paused', $actions);
        self::assertContains('chase.resumed', $actions);
        self::assertContains('invoice.marked_paid', $actions);
    }

    public function testPausingAndResumingThroughTheApi(): void
    {
        $chase = $this->liveChase('INV-CONTROL', 'dana@whitfield.test');
        $this->signIn();

        $paused = $this->postJson('/api/chases/' . $chase['chase_id'] . '/pause', ['_csrf' => $this->csrfToken()]);
        self::assertSame(200, $paused->status, $paused->body);
        self::assertSame(Chase::STATUS_PAUSED, Chase::find($this->tenantId, $chase['chase_id'])['status']);

        $resumed = $this->postJson('/api/chases/' . $chase['chase_id'] . '/resume', ['_csrf' => $this->csrfToken()]);
        self::assertSame(200, $resumed->status, $resumed->body);

        $after = Chase::find($this->tenantId, $chase['chase_id']);
        self::assertSame(Chase::STATUS_ACTIVE, $after['status']);
        self::assertNull($after['paused_reason']);
        self::assertNotNull($after['next_send_at'], 'a resumed chase needs a next send');
    }

    // -------------------------------------------------------------- helpers

    /**
     * @return array{invoice_id:int, chase_id:int, client_id:int}
     */
    private function liveChase(string $number, string $email, bool $send = true): array
    {
        $clientId = Client::findOrCreate($this->tenantId, $email, ['name' => 'Dana Whitfield', 'timezone' => 'UTC']);
        $invoiceId = $this->invoice($clientId, $number, 320000, -18);

        (new ChaseScheduler())->start($this->tenantId, $invoiceId, $this->sequenceId, $this->accountId, $this->now);

        if ($send) {
            (new ChaseSender(new RecordingTransport()))->processDueForTenant($this->tenantId, $this->now);
        }

        return [
            'invoice_id' => $invoiceId,
            'chase_id' => (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'],
            'client_id' => $clientId,
        ];
    }

    private function invoice(
        int $clientId,
        string $number,
        int $cents,
        int $dueOffsetDays,
        string $status = 'open',
        ?int $paidOffsetDays = null,
        string $currency = 'USD'
    ): int {
        $attributes = [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => $cents,
            'currency' => $currency,
            'due_date' => $this->now
                ->modify(($dueOffsetDays >= 0 ? '+' : '-') . abs($dueOffsetDays) . ' days')
                ->format('Y-m-d'),
            'status' => $status,
        ];

        if ($paidOffsetDays !== null) {
            $attributes['paid_at'] = Clock::toDatabase(
                $this->now->modify(($paidOffsetDays >= 0 ? '+' : '-') . abs($paidOffsetDays) . ' days')
            );
            $attributes['paid_source'] = 'manual';
        }

        return Invoice::create($this->tenantId, $attributes);
    }

    /**
     * Bulk data for the performance assertions, inserted in one transaction so
     * seeding time does not dominate the test.
     */
    private function seedAtScale(int $invoiceCount, int $chaseCount): void
    {
        Database::transaction(function () use ($invoiceCount): void {
            $clients = [];
            for ($i = 0; $i < 40; $i++) {
                $clients[] = Client::create($this->tenantId, [
                    'name' => 'Client ' . $i,
                    'email' => 'client' . $i . '@bigco.test',
                    'company' => 'BigCo ' . $i,
                ]);
            }

            for ($i = 1; $i <= $invoiceCount; $i++) {
                $status = $i % 7 === 0 ? 'paid' : 'open';
                $this->invoice(
                    $clients[$i % 40],
                    'INV-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    50000 + ($i * 137),
                    -($i % 90),
                    $status,
                    $status === 'paid' ? -($i % 40) : null
                );
            }
        });

        Database::transaction(function () use ($chaseCount): void {
            $scheduler = new ChaseScheduler();
            $seeded = 0;

            foreach (Invoice::open($this->tenantId, $chaseCount * 2) as $invoice) {
                if ($seeded >= $chaseCount) {
                    break;
                }

                $result = $scheduler->start(
                    $this->tenantId, (int) $invoice['id'],
                    $this->sequenceId, $this->accountId, $this->now
                );

                if (!$result['created']) {
                    continue;
                }

                ChaseMessage::create($this->tenantId, [
                    'chase_id' => $result['chase_id'],
                    'email_account_id' => $this->accountId,
                    'position' => 1,
                    'to_email' => 'x@bigco.test',
                    'from_email' => 'ada@studio.test',
                    'subject' => 'Reminder',
                    'body_text' => 'Body',
                    'rfc_message_id' => '<seed' . $result['chase_id'] . '@duely.app>',
                    'status' => ChaseMessage::STATUS_SENT,
                    'sent_at' => Clock::toDatabase($this->now->modify('-2 days')),
                ]);

                $seeded++;
            }
        });
    }

    /**
     * Sign in as the user setUp() created, rather than creating another one.
     * actingAs() always inserts a new user, which collides on the email and
     * would in any case own a different tenant to the fixtures.
     */
    private function signIn(): array
    {
        \Keel\Core\Session::put('user_id', (int) $this->user['id']);
        \Keel\Core\Session::put('user_email', (string) $this->user['email']);
        \Keel\Core\Session::put('organization_id', $this->tenantId);
        \Keel\Core\Auth::setUserId(null);

        return $this->user;
    }

    private function queryCount(): int
    {
        $row = Database::connection()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();

        return (int) $row['Value'];
    }
}
