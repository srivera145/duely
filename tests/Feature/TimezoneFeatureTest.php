<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Models\Chase;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Services\ChaseScheduler;
use Keel\App\Services\Clock;
use Keel\App\Services\CsvImporter;
use Keel\App\Services\TenantContext;
use Keel\App\Services\Timezones;
use Keel\Core\Database;
use Tests\TestCase;

/**
 * Timezones: one for display, one per client for delivery.
 *
 * `clients.timezone` existed and ChaseScheduler always read it, but nothing set
 * it — no form field, no CSV column, no mapping — so every client was UTC and
 * the window arithmetic was being fed the wrong input. There was no workspace
 * zone at all, so every timestamp displayed was UTC.
 *
 * The distinction these tests protect: changing the workspace zone must change
 * only labels, and changing a client's zone must change delivery.
 */
class TimezoneFeatureTest extends TestCase
{
    private int $tenantId;
    private int $sequenceId;
    private int $accountId;
    private DateTimeImmutable $now;

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

        // Mid-July: Denver is on MDT, UTC-6.
        $this->now = new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
    }

    // ----------------------- self-check: Denver 09:00 is stored as 15:00 UTC

    public function testAClientInDenverIsScheduledAtFifteenHundredUtcInSummer(): void
    {
        $invoiceId = $this->invoice('INV-DENVER', 'America/Denver', '2026-07-01');

        $start = (new ChaseScheduler())->start(
            $this->tenantId,
            $invoiceId,
            $this->sequenceId,
            $this->accountId,
            $this->now
        );

        $chase = Chase::find($this->tenantId, (int) $start['chase_id']);
        $stored = Clock::fromDatabase($chase['next_send_at']);

        // $now is 12:00 UTC, which is 06:00 in Denver -- before the window
        // opens -- so the scheduler shifts forward to the opening.
        // Stored UTC, read as UTC.
        self::assertSame('15:00', $stored->format('H:i'), 'next_send_at is not 15:00 UTC');

        // And that instant is 09:00 in Denver, which is the thing that matters.
        self::assertSame(
            '09:00',
            $stored->setTimezone(new DateTimeZone('America/Denver'))->format('H:i')
        );
    }

    public function testAClientInLondonLandsAtADifferentUtcHourFromDenver(): void
    {
        $denver = $this->invoice('INV-D', 'America/Denver', '2026-07-01');
        $london = $this->invoice('INV-L', 'Europe/London', '2026-07-01', 'ben@london.test');

        $scheduler = new ChaseScheduler();

        // 02:00 UTC: 03:00 in London and 20:00 the previous evening in Denver.
        // Both are outside the 09:00-16:00 window, so both get shifted to the
        // next window opening -- which is the comparison being made. At a time
        // when either is already inside its window the scheduler sends now, and
        // the test would be measuring the clock rather than the zone.
        $earlyMorning = new DateTimeImmutable('2026-07-15 02:00:00', new DateTimeZone('UTC'));

        $a = Chase::find($this->tenantId, (int) $scheduler->start(
            $this->tenantId, $denver, $this->sequenceId, $this->accountId, $earlyMorning
        )['chase_id']);

        $b = Chase::find($this->tenantId, (int) $scheduler->start(
            $this->tenantId, $london, $this->sequenceId, $this->accountId, $earlyMorning
        )['chase_id']);

        // 09:00 BST is 08:00 UTC; 09:00 MDT is 15:00 UTC. Same window, seven
        // hours apart -- which only happens if the client zone is real data.
        self::assertSame('08:00', Clock::fromDatabase($b['next_send_at'])->format('H:i'));
        self::assertSame('15:00', Clock::fromDatabase($a['next_send_at'])->format('H:i'));
    }

    // ------------------------------------------------- self-check: DST

    public function testAChaseAcrossADstTransitionLandsAtTheRightLocalHour(): void
    {
        // Due in late October. The day-3 step lands after the US clocks go back
        // on 1 November 2026, so the UTC hour must change and the local hour
        // must not.
        $invoiceId = $this->invoice('INV-DST', 'America/Denver', '2026-10-30');

        $beforeTransition = new DateTimeImmutable('2026-10-31 12:00:00', new DateTimeZone('UTC'));

        $start = (new ChaseScheduler())->start(
            $this->tenantId,
            $invoiceId,
            $this->sequenceId,
            $this->accountId,
            $beforeTransition
        );

        $stored = Clock::fromDatabase(
            Chase::find($this->tenantId, (int) $start['chase_id'])['next_send_at']
        );

        $local = $stored->setTimezone(new DateTimeZone('America/Denver'));

        // The local hour is what the user configured, on both sides of the
        // transition. A fixed offset would have drifted by an hour here.
        self::assertGreaterThanOrEqual(9, (int) $local->format('G'), 'landed before the window opens');
        self::assertLessThanOrEqual(16, (int) $local->format('G'), 'landed after the window closes');

        // And prove the transition really is in play: MST, not MDT.
        self::assertSame('MST', $local->format('T'));
    }

    // ---------- self-check: the workspace zone changes labels and nothing else

    public function testChangingTheWorkspaceTimezoneChangesNothingInTheDatabase(): void
    {
        $invoiceId = $this->invoice('INV-LABEL', 'America/Denver', '2026-07-01');

        $start = (new ChaseScheduler())->start(
            $this->tenantId,
            $invoiceId,
            $this->sequenceId,
            $this->accountId,
            $this->now
        );

        $before = Chase::find($this->tenantId, (int) $start['chase_id'])['next_send_at'];

        self::assertTrue(Timezones::setForWorkspace($this->tenantId, 'America/Denver'));

        $after = Chase::find($this->tenantId, (int) $start['chase_id'])['next_send_at'];

        // Byte for byte. This is a presentation setting and must not move a
        // single stored moment.
        self::assertSame($before, $after, 'changing the display zone moved a stored datetime');

        // The label does change.
        $stored = Clock::fromDatabase($after);
        self::assertSame('15:00', Timezones::render($stored, 'UTC', 'H:i'));
        self::assertSame('09:00', Timezones::render($stored, 'America/Denver', 'H:i'));
    }

    public function testTheDashboardRendersTimesInTheWorkspaceZone(): void
    {
        $this->signIn();
        Timezones::setForWorkspace($this->tenantId, 'America/Denver');

        $body = $this->get('/dashboard')->body;

        // The label used to be hardcoded to UTC because the resolver read
        // users.timezone, a column that has never existed.
        self::assertStringContainsString('America/Denver', $body);
        self::assertStringNotContainsString('Times shown in UTC', $body);
    }

    // ------------------------------------- self-check: junk is rejected

    public function testAnInvalidTimezoneIsRejectedOnTheClientForm(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/clients', [
            '_csrf' => $this->csrfToken(),
            'name' => 'Dana Whitfield',
            'email' => 'dana@whitfield.test',
            'timezone' => 'Mars/Olympus_Mons',
        ]);

        self::assertSame(422, $response->status);
        self::assertArrayHasKey('timezone', $response->json()['errors']);
        self::assertStringContainsString(
            'not a timezone Duely recognises',
            $response->json()['errors']['timezone']
        );
    }

    public function testAnOffsetOrAbbreviationIsNotAcceptedAsATimezone(): void
    {
        // DateTimeZone's constructor accepts both, and neither survives a DST
        // transition -- which is exactly what the scheduler depends on. So the
        // check is against the IANA list, not against the constructor.
        self::assertFalse(Timezones::isValid('+05:00'));
        self::assertFalse(Timezones::isValid('EST5EDT'));
        self::assertFalse(Timezones::isValid(''));
        self::assertTrue(Timezones::isValid('America/Denver'));
        self::assertTrue(Timezones::isValid('UTC'));
    }

    public function testAnInvalidTimezoneIsRejectedOnImportWithAReadableError(): void
    {
        $csv = "number,client email,amount,due date,timezone\n"
            . "INV-TZ-1,dana@whitfield.test,3200.00,2026-07-01,Mars/Olympus_Mons\n";

        $result = (new CsvImporter())->validate($this->tenantId, $csv, $this->mapping(true));

        self::assertSame([], $result['valid']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('Mars/Olympus_Mons', $result['errors'][0]['reason']);
        self::assertStringContainsString('America/Denver', $result['errors'][0]['reason']);
    }

    // ------------------------ self-check: a CSV timezone column, and without

    public function testACsvTimezoneColumnSetsTheClientZone(): void
    {
        Timezones::setForWorkspace($this->tenantId, 'America/Chicago');

        $csv = "number,client email,amount,due date,tz\n"
            . "INV-TZ-2,dana@whitfield.test,3200.00,2026-07-01,Europe/Lisbon\n";

        (new CsvImporter())->commit($this->tenantId, $csv, $this->mapping(true));

        self::assertSame(
            'Europe/Lisbon',
            Client::findByEmail($this->tenantId, 'dana@whitfield.test')['timezone']
        );
    }

    public function testACsvWithNoTimezoneColumnUsesTheWorkspaceDefault(): void
    {
        Timezones::setForWorkspace($this->tenantId, 'America/Chicago');

        $csv = "number,client email,amount,due date\n"
            . "INV-TZ-3,sam@chen.test,3200.00,2026-07-01\n";

        (new CsvImporter())->commit($this->tenantId, $csv, $this->mapping(false));

        // Not UTC. UTC is the wrong guess for almost everyone, and it was the
        // only guess before this change.
        self::assertSame(
            'America/Chicago',
            Client::findByEmail($this->tenantId, 'sam@chen.test')['timezone']
        );
    }

    public function testANewClientOnTheFormDefaultsToTheWorkspaceZone(): void
    {
        $this->signIn();
        Timezones::setForWorkspace($this->tenantId, 'Europe/Berlin');

        $response = $this->postJson('/api/clients', [
            '_csrf' => $this->csrfToken(),
            'name' => 'Dana Whitfield',
            'email' => 'dana@whitfield.test',
        ]);

        self::assertSame(200, $response->status);
        self::assertSame(
            'Europe/Berlin',
            Client::findByEmail($this->tenantId, 'dana@whitfield.test')['timezone']
        );
    }

    // ------------------------------ self-check: the backfill is offered, not done

    public function testExistingClientsAreNotRewrittenWhenTheWorkspaceZoneChanges(): void
    {
        $clientId = Client::create($this->tenantId, [
            'name' => 'Dana Whitfield',
            'email' => 'dana@whitfield.test',
            'timezone' => 'UTC',
        ]);

        Timezones::setForWorkspace($this->tenantId, 'America/Denver');

        // Silently moving them would move every reminder scheduled for them by
        // hours. There is no safe guess, so the user decides.
        self::assertSame('UTC', Client::find($this->tenantId, $clientId)['timezone']);
        self::assertSame(1, Client::countOnTimezone($this->tenantId, 'UTC'));
    }

    public function testTheClientsListFlagsStaleUtcClientsAndOffersTheBackfill(): void
    {
        $this->signIn();

        Client::create($this->tenantId, [
            'name' => 'Dana Whitfield',
            'email' => 'dana@whitfield.test',
            'timezone' => 'UTC',
        ]);

        // On a UTC workspace there is nothing to flag.
        self::assertStringNotContainsString('still set to UTC', $this->get('/clients')->body);

        Timezones::setForWorkspace($this->tenantId, 'America/Denver');

        $body = $this->get('/clients')->body;
        self::assertStringContainsString('still set to UTC', $body);
        self::assertStringContainsString('/clients/timezone-backfill', $body);
    }

    public function testTheBackfillMovesOnlyClientsStillOnTheDefault(): void
    {
        $this->signIn();

        $stale = Client::create($this->tenantId, [
            'name' => 'Dana', 'email' => 'dana@whitfield.test', 'timezone' => 'UTC',
        ]);
        $chosen = Client::create($this->tenantId, [
            'name' => 'Ben', 'email' => 'ben@london.test', 'timezone' => 'Europe/London',
        ]);

        Timezones::setForWorkspace($this->tenantId, 'America/Denver');

        $this->post('/clients/timezone-backfill', ['_csrf' => $this->csrfToken()]);

        self::assertSame('America/Denver', Client::find($this->tenantId, $stale)['timezone']);
        // A zone somebody chose is never overwritten by a bulk action.
        self::assertSame('Europe/London', Client::find($this->tenantId, $chosen)['timezone']);
    }

    // ------------------------------------------- self-check: the settings page

    public function testTheWorkspaceTimezoneCanBeSetAndRejectsJunk(): void
    {
        $this->signIn();

        $this->post('/settings/timezone', [
            '_csrf' => $this->csrfToken(),
            'timezone' => 'America/Denver',
        ]);

        self::assertSame('America/Denver', Timezones::forWorkspace($this->tenantId));

        $response = $this->post('/settings/timezone', [
            '_csrf' => $this->csrfToken(),
            'timezone' => 'Nowhere/Nothing',
        ]);

        self::assertSame(302, $response->status);
        self::assertStringContainsString('error=', (string) $response->header('Location'));
        self::assertSame('America/Denver', Timezones::forWorkspace($this->tenantId), 'junk overwrote a real zone');
    }

    public function testTheBrowserDetectedZoneOnlyFillsInAnUntouchedWorkspace(): void
    {
        $this->signIn();

        $first = $this->postJson('/api/settings/timezone/detect', [
            '_csrf' => $this->csrfToken(),
            'timezone' => 'America/Denver',
        ]);

        self::assertTrue($first->json()['changed']);
        self::assertSame('America/Denver', Timezones::forWorkspace($this->tenantId));

        // A user travelling with a laptop must not find their invoices
        // relabelled on landing.
        $second = $this->postJson('/api/settings/timezone/detect', [
            '_csrf' => $this->csrfToken(),
            'timezone' => 'Asia/Tokyo',
        ]);

        self::assertFalse($second->json()['changed']);
        self::assertSame('America/Denver', Timezones::forWorkspace($this->tenantId));
    }

    // ------------------------------------------------------------------ helpers

    private function signIn(): void
    {
        \Keel\Core\Session::put('user_id', $this->userId());
        \Keel\Core\Session::put('user_email', 'ada@studio.test');
        \Keel\Core\Session::put('organization_id', $this->tenantId);
        \Keel\Core\Auth::setUserId(null);
    }

    private function userId(): int
    {
        $statement = Database::connection()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $statement->execute(['ada@studio.test']);

        return (int) $statement->fetchColumn();
    }

    private function invoice(
        string $number,
        string $timezone,
        string $dueDate,
        string $email = 'dana@whitfield.test'
    ): int {
        $clientId = Client::findOrCreate($this->tenantId, $email, [
            'name' => 'Dana Whitfield',
            'timezone' => $timezone,
        ]);

        // findOrCreate may have matched an existing row; make the zone explicit.
        Client::update($this->tenantId, $clientId, ['timezone' => $timezone]);

        return Invoice::create($this->tenantId, [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => $dueDate,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function mapping(bool $withTimezone): array
    {
        $mapping = [
            'number' => 0,
            'client_email' => 1,
            'amount' => 2,
            'due_date' => 3,
        ];

        if ($withTimezone) {
            $mapping['timezone'] = 4;
        }

        return $mapping;
    }
}
