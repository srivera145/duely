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
use Keel\App\Services\ChaseSender;
use Keel\App\Services\Clock;
use Keel\App\Services\ImpersonationService;
use Keel\App\Services\PlanService;
use Keel\App\Services\SupportAccessLog;
use Keel\App\Services\TenantContext;
use Keel\Core\Auth;
use Keel\Core\Database;
use Keel\Core\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordingTransport;
use Tests\TestCase;

/**
 * The operator panel.
 *
 * These tests are the enforcement. The privacy page now makes specific promises
 * about what support access can and cannot do, and each one of them is a
 * statement about somebody else's data made in public — so each one is asserted
 * here rather than left to code review.
 */
class SuperAdminFeatureTest extends TestCase
{
    private int $adminId;
    private int $adminTenantId;
    private int $customerId;
    private int $customerTenantId;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->createUser(['email' => 'operator@duely.test', 'name' => 'Santos']);
        $this->adminId = (int) $admin['id'];
        $this->adminTenantId = TenantContext::forUser($this->adminId);

        Database::connection()
            ->prepare('UPDATE users SET is_super_admin = 1 WHERE id = ?')
            ->execute([$this->adminId]);

        $customer = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->customerId = (int) $customer['id'];
        $this->customerTenantId = TenantContext::forUser($this->customerId);

        $this->now = new DateTimeImmutable('2026-08-19 11:00:00', new DateTimeZone('UTC'));
    }

    // ------------------------------- self-check: a non-operator gets a 404

    public static function panelRoutes(): array
    {
        return [
            'operations' => ['/super-admin'],
            'metrics' => ['/super-admin/metrics'],
            'accounts' => ['/super-admin/organizations'],
            'support' => ['/super-admin/support'],
            'audit' => ['/super-admin/audit'],
            'activity' => ['/super-admin/activity'],
        ];
    }

    #[DataProvider('panelRoutes')]
    public function testANonOperatorGetsNotFoundRatherThanForbidden(string $path): void
    {
        $this->signInAsCustomer();

        $response = $this->get($path);

        // 403 would confirm the path exists, which is a free map of the panel
        // for anybody probing. To a customer this is indistinguishable from a
        // typo.
        self::assertSame(404, $response->status, $path . ' revealed itself.');
        self::assertNotSame(403, $response->status);
    }

    public function testAnOperatorReachesEveryPanelPage(): void
    {
        $this->signInAsOperator();

        foreach (array_column(self::panelRoutes(), 0) as $path) {
            self::assertSame(200, $this->get($path)->status, $path . ' did not render.');
        }
    }

    // --------------------- self-check: revoking locks out on the next request

    public function testRevokingSuperAdminLocksTheUserOutWithoutRelogin(): void
    {
        $this->signInAsOperator();
        self::assertSame(200, $this->get('/super-admin')->status);

        // The session is untouched -- only the database row changes.
        Database::connection()
            ->prepare('UPDATE users SET is_super_admin = 0 WHERE id = ?')
            ->execute([$this->adminId]);

        self::assertSame(
            404,
            $this->get('/super-admin')->status,
            'A revoked operator kept access until their next login.'
        );
    }

    // ------------------------------------- self-check: no reason, no access

    public function testSupportAccessWithoutAReasonIsRefused(): void
    {
        $this->signInAsOperator();

        $response = $this->post('/super-admin/support/open', [
            '_csrf' => $this->csrfToken(),
            'tenant_id' => $this->customerTenantId,
            'reason' => 'nope',
        ]);

        self::assertSame(302, $response->status);
        self::assertStringContainsString('error=', (string) ($response->headers['Location'] ?? ''));

        // And nothing was opened, so nothing was recorded as opened.
        self::assertSame([], (new SupportAccessLog())->forTenant($this->customerTenantId));
    }

    public function testWalkingStraightIntoAnAccountWithoutStatingAReasonIsRefused(): void
    {
        $this->signInAsOperator();

        $response = $this->get('/super-admin/support/' . $this->customerTenantId);

        self::assertSame(302, $response->status);
        self::assertStringContainsString('/super-admin/support', (string) ($response->headers['Location'] ?? ''));
    }

    public function testOpeningAnAccountWithAReasonRecordsIt(): void
    {
        $this->signInAsOperator();

        $this->post('/super-admin/support/open', [
            '_csrf' => $this->csrfToken(),
            'tenant_id' => $this->customerTenantId,
            'reason' => 'Ticket 412 - reminders not sending since Tuesday',
        ]);

        self::assertSame(200, $this->get('/super-admin/support/' . $this->customerTenantId)->status);

        $entries = (new SupportAccessLog())->forTenant($this->customerTenantId);

        self::assertNotSame([], $entries);
        self::assertSame('support.account_opened', $entries[0]['action']);
        self::assertStringContainsString('Ticket 412', (string) $entries[0]['reason']);
        self::assertSame('operator@duely.test', $entries[0]['super_admin_email']);
    }

    // ------------------- self-check: the customer sees it in their own feed

    public function testTheTargetTenantsOwnActivityFeedShowsTheAccess(): void
    {
        $this->signInAsOperator();

        $this->post('/super-admin/support/open', [
            '_csrf' => $this->csrfToken(),
            'tenant_id' => $this->customerTenantId,
            'reason' => 'Ticket 412 - looking at the failed send',
        ]);
        $this->get('/super-admin/support/' . $this->customerTenantId);

        // activity_log is what the customer's own activity page reads. This is
        // the promise the privacy page makes by name: they see it without
        // asking, and without us choosing to tell them.
        $statement = Database::connection()->prepare(
            'SELECT action, metadata FROM activity_log
             WHERE organization_id = ? AND action LIKE ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$this->customerTenantId, 'support.%']);
        $row = $statement->fetch();

        self::assertIsArray($row, 'The customer cannot see that their account was opened.');
        self::assertStringContainsString('Ticket 412', (string) $row['metadata']);
        self::assertStringContainsString('operator@duely.test', (string) $row['metadata']);
    }

    public function testTheCustomerCanActuallyReadTheAccessOnTheirOwnActivityPage(): void
    {
        // The privacy page says "you can see it from your own account, without
        // asking us". That is only true if there is a page, routed, that a
        // single-tenant customer can open.
        $this->signInAsOperator();

        $this->post('/super-admin/support/open', [
            '_csrf' => $this->csrfToken(),
            'tenant_id' => $this->customerTenantId,
            'reason' => 'Ticket 412 - checking the failed send',
        ]);
        $this->get('/super-admin/support/' . $this->customerTenantId);

        $this->signInAsCustomer();

        $response = $this->get('/settings/activity');

        self::assertSame(200, $response->status, 'The customer has no page to read this on.');
        self::assertStringContainsString('support.', $response->body);
    }

    // ------------------------ self-check: credentials are never decrypted

    public function testNoPanelCodePathDecryptsAMailboxCredential(): void
    {
        // A grep the build fails on. The privacy page says this in as many
        // words, and it is the one promise a user actually feels -- they handed
        // over an email password.
        $offenders = [];

        $files = array_merge(
            glob(dirname(__DIR__, 2) . '/src/App/Controllers/SuperAdmin/*.php') ?: [],
            glob(dirname(__DIR__, 2) . '/views/super-admin/*.php') ?: [],
            [
                dirname(__DIR__, 2) . '/src/App/Services/SupportAccessLog.php',
                dirname(__DIR__, 2) . '/src/App/Services/ImpersonationService.php',
                dirname(__DIR__, 2) . '/src/App/Services/OperationsMonitor.php',
                dirname(__DIR__, 2) . '/src/App/Services/PlatformMetrics.php',
            ]
        );

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['Crypto::decrypt', 'password_encrypted', 'oauth_access_token', 'oauth_refresh_token'] as $needle) {
                foreach (explode("\n", $contents) as $number => $line) {
                    $trimmed = ltrim($line);

                    // Comments explaining the absence say the words.
                    if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                        continue;
                    }

                    if (str_contains($line, $needle)) {
                        $offenders[] = basename($file) . ':' . ($number + 1) . ' ' . $needle;
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'The panel touches a credential: ' . implode(', ', $offenders));
    }

    public function testTheSupportViewShowsMailboxConfigButNoCredential(): void
    {
        $this->signInAsOperator();
        $this->mailboxFor($this->customerTenantId);

        $this->post('/super-admin/support/open', [
            '_csrf' => $this->csrfToken(),
            'tenant_id' => $this->customerTenantId,
            'reason' => 'Ticket 412 - checking the mailbox settings',
        ]);

        $body = $this->get('/super-admin/support/' . $this->customerTenantId)->body;

        // What it must show, to be useful at all.
        self::assertStringContainsString('smtp.test', $body);
        self::assertStringContainsString('ada@studio.test', $body);

        // What it must never show. Not the plaintext, and not the ciphertext:
        // a stored blob on a support screen is an invitation to try it offline.
        self::assertStringNotContainsString('super-secret-app-password', $body);
        self::assertStringNotContainsString('smtp_password_encrypted', $body);
    }

    // ---------------------------- self-check: impersonation cannot send mail

    public function testAnImpersonatedSessionCannotSendEmail(): void
    {
        $this->startImpersonation();

        $transport = new RecordingTransport();

        // The middleware refuses the request before anything reaches a
        // controller, so the transport is never constructed on that path. This
        // asserts the stronger thing: zero sends, whatever route was tried.
        foreach ([
            '/api/email-account/send-test',
            '/api/email-account/test',
            '/api/email-account/save',
        ] as $path) {
            $response = $this->postJson($path, ['_csrf' => $this->csrfToken()]);

            self::assertSame(403, $response->status, $path . ' was not blocked.');
            self::assertStringContainsString('support session cannot', (string) ($response->json()['error'] ?? ''));
        }

        self::assertSame([], $transport->sent, 'A support session sent mail.');
    }

    public function testAnImpersonatedSessionCannotReachTheRestOfTheBlockedList(): void
    {
        $this->startImpersonation();

        foreach ([
            '/api/billing/trial' => 'billing',
            '/settings/payments/connect' => 'Stripe',
            '/settings/payments/disconnect' => 'Stripe',
            '/api/invoices/1/delete' => 'deletion',
        ] as $path => $what) {
            $response = $this->postJson($path, ['_csrf' => $this->csrfToken()]);

            self::assertSame(403, $response->status, $what . ' was not blocked at ' . $path);
        }

        // /settings/members/* is only routed when MULTI_TENANCY_ENABLED is on,
        // which it is not in this configuration. Asserting the guard's own
        // decision rather than a request, so the rule is still covered on an
        // install where the route does exist.
        foreach (['/settings/members/invite', '/settings/organization', '/api/email-account/save'] as $path) {
            self::assertTrue($this->guardBlocks($path), $path . ' is not on the blocked list.');
        }
    }

    public function testAnImpersonatedSessionCanStillReadTheProduct(): void
    {
        $this->startImpersonation();

        // Seeing what the customer sees is the entire purpose. If reads were
        // blocked too, the feature would be pointless and somebody would
        // rebuild it worse.
        foreach (['/dashboard', '/invoices', '/clients'] as $path) {
            self::assertSame(200, $this->get($path)->status, $path . ' should be readable.');
        }
    }

    public function testAnImpersonatedSessionSeesTheCustomersDataNotTheOperators(): void
    {
        $this->invoiceFor($this->customerTenantId, 'CUSTOMER-1');
        $this->invoiceFor($this->adminTenantId, 'OPERATOR-1');

        $this->startImpersonation();

        $body = $this->get('/invoices')->body;

        self::assertStringContainsString('CUSTOMER-1', $body);
        self::assertStringNotContainsString('OPERATOR-1', $body);
    }

    // ------------------ self-check: impersonation cannot reach the panel

    #[DataProvider('panelRoutes')]
    public function testAnImpersonatedSessionCannotReachThePanel(string $path): void
    {
        $this->startImpersonation();

        // Escalating back out would let the customer's session be a springboard
        // into the panel, and would make every audit entry from that point name
        // the wrong actor.
        self::assertNotSame(200, $this->get($path)->status, $path . ' was reachable while impersonating.');
    }

    // -------------------------- self-check: thirty minutes, no renewal

    public function testAnImpersonationSessionExpiresAtThirtyMinutes(): void
    {
        $startedAt = $this->startImpersonation();

        $service = new ImpersonationService();
        self::assertTrue($service->isActive());

        // Twenty-nine minutes: still live.
        self::assertTrue($service->isActive($startedAt->modify('+29 minutes')));

        // Thirty-one: over, with no sweep having run -- the expiry is checked
        // when somebody asks, so there is no window where a job has not caught
        // up yet.
        self::assertFalse(
            $service->isActive($startedAt->modify('+31 minutes')),
            'The session outlived its expiry.'
        );
    }

    public function testAnExpiredSessionCannotBeRenewedAndStopsSeeingCustomerData(): void
    {
        $this->startImpersonation();

        // Age the row rather than the clock, so the expiry is tested through
        // the same path a real request takes.
        Database::connection()
            ->prepare('UPDATE impersonation_sessions SET expires_at = ? WHERE impersonator_user_id = ?')
            ->execute([Clock::toDatabase(Clock::now()->modify('-1 minute')), $this->adminId]);

        self::assertFalse((new ImpersonationService())->isActive());

        // And there is no renewal path: nothing in the service extends
        // expires_at, so the only way on is a new session with a new reason.
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/App/Services/ImpersonationService.php'
        );
        self::assertStringNotContainsString('SET expires_at', $source);
    }

    public function testEndingASessionIsRecordedAndReturnsTheOperatorToThemselves(): void
    {
        $this->startImpersonation();

        $response = $this->post('/impersonation/stop', ['_csrf' => $this->csrfToken()]);

        self::assertSame(302, $response->status);
        self::assertFalse((new ImpersonationService())->isActive());

        $actions = array_column((new SupportAccessLog())->forTenant($this->customerTenantId), 'action');
        self::assertContains('impersonation.ended', $actions);
    }

    // ------------------------------ self-check: the banner is unmissable

    public function testEveryPageInAnImpersonatedSessionCarriesTheBanner(): void
    {
        $this->startImpersonation();

        foreach (['/dashboard', '/invoices', '/clients', '/sequences'] as $path) {
            $body = $this->get($path)->body;

            self::assertStringContainsString('Support session', $body, $path . ' has no banner.');
            self::assertStringContainsString('ada@studio.test', $body, $path . ' does not name the target.');
            self::assertStringContainsString('/impersonation/stop', $body, $path . ' offers no way out.');
        }
    }

    public function testAnOrdinarySessionCarriesNoBanner(): void
    {
        $this->signInAsCustomer();

        self::assertStringNotContainsString('Support session', $this->get('/dashboard')->body);
    }

    // ------------------------- self-check: the audit trail is append-only

    public function testNothingInTheCodebaseDeletesOrUpdatesTheAuditTrail(): void
    {
        // The operator being audited is the same person who deploys the code,
        // so the protection is that the vocabulary does not exist. A grep is
        // the honest way to assert that.
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach (explode("\n", $contents) as $number => $line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                $normalised = strtolower($line);

                if (
                    (str_contains($normalised, 'delete from support_access_log')
                        || str_contains($normalised, 'update support_access_log')
                        || str_contains($normalised, 'truncate table support_access_log'))
                ) {
                    $offenders[] = $file->getFilename() . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'The audit trail can be erased: ' . implode(', ', $offenders));
    }

    public function testTheAuditServiceExposesNoWayToRemoveAnEntry(): void
    {
        $methods = get_class_methods(SupportAccessLog::class);

        foreach ($methods as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/^(delete|remove|purge|clear|update|edit)/i',
                $method,
                'SupportAccessLog::' . $method . ' gives the codebase vocabulary it should not have.'
            );
        }
    }

    // -------------------- self-check: a founding grant cannot produce slot 51

    public function testGrantingAFoundingSlotThroughThePanelCannotProduceSlotFiftyOne(): void
    {
        $this->signInAsOperator();

        // Take all fifty by the ordinary path.
        $plans = new PlanService();

        for ($i = 0; $i < PlanService::FOUNDING_SLOTS; $i++) {
            $tenantId = $this->workspace('Cohort ' . $i);
            self::assertTrue($plans->claimFoundingSlot($tenantId)['claimed']);
        }

        // Now the operator tries to grant one more. It must fail rather than
        // inventing a slot -- which is exactly what a direct UPDATE would do.
        $response = $this->post('/super-admin/organizations/' . $this->customerTenantId . '/founding', [
            '_csrf' => $this->csrfToken(),
            'grant' => 'yes',
            'reason' => 'Promised on a call last week',
        ]);

        self::assertSame(302, $response->status);

        $claimed = (int) Database::connection()
            ->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')
            ->fetchColumn();

        self::assertSame(PlanService::FOUNDING_SLOTS, $claimed, 'The cohort grew past fifty.');

        $highest = (int) Database::connection()
            ->query('SELECT MAX(slot_number) FROM founding_slots WHERE tenant_id IS NOT NULL')
            ->fetchColumn();

        self::assertLessThanOrEqual(PlanService::FOUNDING_SLOTS, $highest, 'Slot 51 exists.');

        $organization = $this->organization($this->customerTenantId);
        self::assertSame(0, (int) $organization['is_founding']);
    }

    // ------------------------------ self-check: administration is recorded

    public function testEveryAdministrativeActionIsRecordedAgainstTheTarget(): void
    {
        $this->signInAsOperator();

        $this->post('/super-admin/organizations/' . $this->customerTenantId . '/trial', [
            '_csrf' => $this->csrfToken(),
            'days' => 30,
            'reason' => 'Goodwill after the outage',
        ]);

        $this->post('/super-admin/organizations/' . $this->customerTenantId . '/plan', [
            '_csrf' => $this->csrfToken(),
            'plan' => PlanService::PLAN_STUDIO,
            'reason' => 'Comped for the pilot',
        ]);

        $actions = array_column((new SupportAccessLog())->forTenant($this->customerTenantId), 'action');

        self::assertContains('account.trial_extended', $actions);
        self::assertContains('account.plan_changed', $actions);

        // And it actually did the thing.
        self::assertSame(PlanService::PLAN_STUDIO, (string) $this->organization($this->customerTenantId)['plan']);
    }

    public function testADestructiveActionNeedsTheOrganizationNameTyped(): void
    {
        $this->signInAsOperator();
        $this->chaseFor($this->customerTenantId);

        // Wrong name: nothing happens.
        $this->post('/super-admin/organizations/' . $this->customerTenantId . '/pause-chases', [
            '_csrf' => $this->csrfToken(),
            'confirm_name' => 'not the name',
            'reason' => 'Testing the confirmation',
        ]);

        self::assertSame(1, $this->liveChaseCount($this->customerTenantId), 'A mistyped name still acted.');

        // Right name: it happens.
        $this->post('/super-admin/organizations/' . $this->customerTenantId . '/pause-chases', [
            '_csrf' => $this->csrfToken(),
            'confirm_name' => (string) $this->organization($this->customerTenantId)['name'],
            'reason' => 'Client asked us to stop everything',
        ]);

        self::assertSame(0, $this->liveChaseCount($this->customerTenantId));
    }

    public function testDisablingAnAccountStopsItsRemindersAsWell(): void
    {
        $this->signInAsOperator();
        $this->chaseFor($this->customerTenantId);

        $this->post('/super-admin/organizations/' . $this->customerTenantId . '/disable', [
            '_csrf' => $this->csrfToken(),
            'confirm_name' => (string) $this->organization($this->customerTenantId)['name'],
            'reason' => 'Payment fraud investigation',
        ]);

        // A suspended workspace that keeps emailing its clients is the worst
        // possible version of "disabled".
        self::assertSame(0, $this->liveChaseCount($this->customerTenantId));
        self::assertNotEmpty($this->organization($this->customerTenantId)['disabled_at']);
    }

    // ------------------------------- self-check: page views are recorded

    public function testOpeningAPanelPageIsRecordedEvenThoughItChangesNothing(): void
    {
        $this->signInAsOperator();
        $this->get('/super-admin');
        $this->get('/super-admin/metrics');

        $actions = array_column((new SupportAccessLog())->recent(50), 'action');

        // Read access is the access that matters here.
        self::assertContains('operations.view', $actions);
        self::assertContains('metrics.view', $actions);
    }

    // ---------------------------- self-check: operators are not impersonable

    public function testASuperAdminCannotBeImpersonated(): void
    {
        $other = $this->createUser(['email' => 'second-operator@duely.test']);
        Database::connection()
            ->prepare('UPDATE users SET is_super_admin = 1 WHERE id = ?')
            ->execute([(int) $other['id']]);

        $this->signInAsOperator();

        $result = (new ImpersonationService())->start(
            $this->adminId,
            (int) $other['id'],
            'Trying to impersonate a peer operator'
        );

        // Two people with the same powers in one audit trail, and no way to
        // tell which of them acted.
        self::assertFalse($result['ok']);
        self::assertStringContainsString('cannot be impersonated', (string) $result['error']);
    }

    public function testStartingASessionWithoutAReasonIsRefused(): void
    {
        $this->signInAsOperator();

        $result = (new ImpersonationService())->start($this->adminId, $this->customerId, 'oops');

        self::assertFalse($result['ok']);
        self::assertSame(
            0,
            (int) Database::connection()
                ->query('SELECT COUNT(*) FROM impersonation_sessions')
                ->fetchColumn()
        );
    }

    // ------------------------------------------------------------------ setup

    private function signInAsOperator(): void
    {
        Session::put('user_id', $this->adminId);
        Session::put('user_email', 'operator@duely.test');
        Session::put('organization_id', $this->adminTenantId);
        Session::put('issued_at', Clock::toDatabase(Clock::now()));
        Session::forget(ImpersonationService::SESSION_KEY);
        Auth::setUserId(null);
    }

    private function signInAsCustomer(): void
    {
        Session::put('user_id', $this->customerId);
        Session::put('user_email', 'ada@studio.test');
        Session::put('organization_id', $this->customerTenantId);
        Session::put('issued_at', Clock::toDatabase(Clock::now()));
        Session::forget(ImpersonationService::SESSION_KEY);
        Auth::setUserId(null);
    }

    /**
     * An operator inside a live support session, by the real code path.
     */
    private function startImpersonation(): DateTimeImmutable
    {
        $this->signInAsOperator();

        // Started at the real clock, not $this->now. Expiry is checked against
        // the real clock on every request, so a session stamped in the fixed
        // past would already be over before the first assertion.
        $startedAt = Clock::now();

        $result = (new ImpersonationService())->start(
            $this->adminId,
            $this->customerId,
            'Ticket 412 - reproducing the missing reminder',
            $startedAt
        );

        self::assertTrue($result['ok'], (string) $result['error']);

        return $startedAt;
    }

    /**
     * Would the guard refuse a POST to this path?
     *
     * Runs the middleware directly, for paths whose routes depend on a config
     * flag. Response::abort throws in capture mode, so a refusal is catchable.
     */
    private function guardBlocks(string $path): bool
    {
        $before = [$_SERVER['REQUEST_METHOD'] ?? null, $_SERVER['REQUEST_URI'] ?? null];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $path;

        try {
            (new \Keel\App\Middleware\ImpersonationGuardMiddleware())
                ->handle(new \Keel\Core\Request(), static fn (): string => 'allowed');

            return false;
        } catch (\Keel\Core\CapturedResponseException $refused) {
            return $refused->status === 403;
        } finally {
            [$_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']] = $before;
        }
    }

    private function workspace(string $name): int
    {
        $connection = Database::connection();
        $connection->prepare('INSERT INTO organizations (name, slug) VALUES (?, ?)')
            ->execute([$name, strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(3))]);

        return (int) $connection->lastInsertId();
    }

    private function organization(int $tenantId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $statement->execute([$tenantId]);

        return $statement->fetch() ?: [];
    }

    private function mailboxFor(int $tenantId): int
    {
        return EmailAccount::create($tenantId, [
            'from_name' => 'Ada Lovelace',
            'from_email' => 'ada@studio.test',
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
            'smtp_username' => 'ada@studio.test',
            'smtp_password' => 'super-secret-app-password',
            'imap_host' => 'imap.test',
            'imap_port' => 993,
            'imap_username' => 'ada@studio.test',
            'imap_password' => 'super-secret-app-password',
            'status' => EmailAccount::STATUS_ACTIVE,
            'is_default' => 1,
        ]);
    }

    private function invoiceFor(int $tenantId, string $number): int
    {
        $clientId = Client::findOrCreate($tenantId, 'dana+' . $tenantId . '@client.test', [
            'name' => 'Dana Whitfield',
            'company' => 'Whitfield & Partners',
            'timezone' => 'UTC',
        ]);

        return Invoice::create($tenantId, [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => $this->now->modify('-18 days')->format('Y-m-d'),
        ]);
    }

    private function chaseFor(int $tenantId): int
    {
        $accountId = $this->mailboxFor($tenantId);
        $invoiceId = $this->invoiceFor($tenantId, 'CHASE-' . $tenantId);

        $start = (new ChaseScheduler())->start(
            $tenantId,
            $invoiceId,
            (int) Sequence::defaultSequence($tenantId)['id'],
            $accountId,
            $this->now
        );

        return (int) $start['chase_id'];
    }

    private function liveChaseCount(int $tenantId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM chases WHERE tenant_id = ? AND status IN (?, ?)'
        );
        $statement->execute([$tenantId, Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE]);

        return (int) $statement->fetchColumn();
    }
}
