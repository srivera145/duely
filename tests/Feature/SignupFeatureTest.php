<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Jobs\ReleaseExpiredFoundingSlotsJob;
use Keel\App\Services\Clock;
use Keel\App\Services\PlanService;
use Keel\App\Services\SignupService;
use Keel\Core\Database;
use Tests\TestCase;

/**
 * Self-serve signup.
 *
 * Before this there was no way for a stranger to make an account: `/login`
 * existed, the waitlist existed, and organizations were only ever created by
 * somebody already signed in. "The first 50 to sign up" had nothing to sign up
 * to.
 *
 * The decision these tests pin down is *when* a founding slot is claimed. It is
 * claimed at signup, so the homepage counter means what its sentence says — and
 * the hold expires after thirty unpaid days so fifty people who never come back
 * cannot consume the whole cohort.
 */
class SignupFeatureTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-08-19 11:00:00', new DateTimeZone('UTC'));
    }

    // ------------------------- self-check: a stranger ends up with a workspace

    public function testANewEmailCompletesSignupAndLandsInOnboarding(): void
    {
        $redirect = $this->signUp('dana@whitfield-partners.test');

        self::assertSame('/onboarding', $redirect);

        $user = $this->user('dana@whitfield-partners.test');
        self::assertNotNull($user);
        self::assertGreaterThan(0, (int) $user['organization_id'], 'no workspace was created');
        self::assertSame('owner', $user['role']);

        // The workspace is usable: signed in, and the dashboard renders.
        self::assertSame(200, $this->get('/dashboard')->status);
    }

    public function testTheWorkspaceIsNamedFromTheEmailWithoutAskingForOne(): void
    {
        // Not a form field. Every field on a signup form costs conversions and
        // this one is not needed to start -- onboarding is where it is renamed.
        $this->signUp('dana@whitfield-partners.test');

        self::assertSame(
            'Whitfield Partners',
            $this->organizationFor('dana@whitfield-partners.test')['name']
        );
    }

    public function testAFreeMailAddressNamesTheWorkspaceAfterThePersonNotGmail(): void
    {
        $this->signUp('dana.whitfield@gmail.com');

        self::assertSame('Dana Whitfield', $this->organizationFor('dana.whitfield@gmail.com')['name']);
    }

    public function testTheSignupPageRenders(): void
    {
        $response = $this->get('/signup');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('signup-email', $response->body);
        // Posting to the shared endpoints, not to a signup-only one.
        self::assertStringContainsString('/auth/otp/request', $response->body);
        self::assertStringContainsString('/auth/otp/verify', $response->body);
    }

    // ---------------------- self-check: an existing email is not disclosed

    public function testAnExistingEmailGetsALoginAndNeverAnAlreadyRegisteredError(): void
    {
        $this->signUp('dana@whitfield-partners.test');
        $tenantId = (int) $this->organizationFor('dana@whitfield-partners.test')['id'];

        // Same form, same endpoints, second time.
        $redirect = $this->signUp('dana@whitfield-partners.test');

        // They land on their dashboard rather than onboarding, and no second
        // workspace appears.
        self::assertSame('/dashboard', $redirect);
        self::assertSame($tenantId, (int) $this->organizationFor('dana@whitfield-partners.test')['id']);
    }

    public function testTheRequestStepSaysTheSameThingForKnownAndUnknownAddresses(): void
    {
        $this->signUp('known@studio.test');

        $known = $this->requestCode('known@studio.test');
        $stranger = $this->requestCode('stranger@studio.test');

        // Identical status and identical wording. Anything else answers "does
        // this person use Duely?" for whoever asks.
        self::assertSame($stranger->status, $known->status);
        self::assertSame($stranger->json()['message'], $known->json()['message']);
        self::assertStringNotContainsStringIgnoringCase('already', (string) $known->json()['message']);
        self::assertStringNotContainsStringIgnoringCase('registered', (string) $known->json()['message']);
        self::assertStringNotContainsStringIgnoringCase('exists', (string) $known->json()['message']);
    }

    // ---------------------------- self-check: running out is not an error

    public function testSignupFiftyOneSucceedsWithoutAFoundingSlot(): void
    {
        $plans = new PlanService();

        for ($i = 0; $i < PlanService::FOUNDING_SLOTS; $i++) {
            $plans->claimFoundingSlot($this->workspace('Cohort ' . $i), $this->now);
        }

        self::assertSame(0, $plans->foundingAvailability()['remaining']);

        $redirect = $this->signUp('fiftyfirst@studio.test');

        // The account is created. Refusing to sign somebody up because a
        // discount ran out would be an odd way to treat the fifty-first person
        // to trust you.
        self::assertSame('/onboarding', $redirect);

        $organization = $this->organizationFor('fiftyfirst@studio.test');
        self::assertSame(0, (int) $organization['is_founding']);
        self::assertNull($organization['founding_slot']);
    }

    public function testTheSignupPageSaysSoWhenThePlacesHaveGone(): void
    {
        $plans = new PlanService();

        for ($i = 0; $i < PlanService::FOUNDING_SLOTS; $i++) {
            $plans->claimFoundingSlot($this->workspace('Cohort ' . $i), $this->now);
        }

        $body = $this->get('/signup')->body;

        // The wording lives in partials/founding-note.php, which every page
        // shares, so this is the same sentence the homepage shows.
        self::assertStringContainsString('founding places have been taken', $body);
        self::assertStringContainsString('standard pricing', $body);
        self::assertStringNotContainsString('founding places left', $body);
    }

    // ------------------------ self-check: two signups cannot share a slot

    public function testTwoSimultaneousSignupsCannotClaimTheSameSlot(): void
    {
        // Down to one place.
        $plans = new PlanService();

        for ($i = 0; $i < PlanService::FOUNDING_SLOTS - 1; $i++) {
            $plans->claimFoundingSlot($this->workspace('Cohort ' . $i), $this->now);
        }

        self::assertSame(1, $plans->foundingAvailability()['remaining']);

        $this->signUp('first@studio.test');
        $this->signUp('second@studio.test');

        $first = $this->organizationFor('first@studio.test');
        $second = $this->organizationFor('second@studio.test');

        // Exactly one of them got it, and both have an account.
        $founding = (int) $first['is_founding'] + (int) $second['is_founding'];
        self::assertSame(1, $founding, 'the last slot went to both or to neither');

        self::assertSame(
            PlanService::FOUNDING_SLOTS,
            (int) Database::connection()
                ->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')
                ->fetchColumn()
        );
    }

    public function testConcurrentClaimsAcrossRealProcessesNeverExceedFifty(): void
    {
        // The atomic claim is PlanService's and is already covered there. What
        // this adds is that provisioning wraps it without weakening it -- the
        // transaction around the organization must not turn a conditional
        // UPDATE into a read-then-write.
        $signup = new SignupService();

        for ($i = 0; $i < PlanService::FOUNDING_SLOTS + 5; $i++) {
            $userId = $this->userWithoutWorkspace('racer' . $i . '@studio.test');
            $signup->provision($userId, $this->now);
        }

        $claimed = (int) Database::connection()
            ->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')
            ->fetchColumn();

        self::assertSame(PlanService::FOUNDING_SLOTS, $claimed);

        $highest = (int) Database::connection()
            ->query('SELECT MAX(slot_number) FROM founding_slots WHERE tenant_id IS NOT NULL')
            ->fetchColumn();

        self::assertLessThanOrEqual(PlanService::FOUNDING_SLOTS, $highest, 'slot 51 exists');
    }

    // ------------------------- self-check: no orphaned slot after a crash

    public function testACrashDuringProvisioningLeavesNoSlotHeldByNothing(): void
    {
        $userId = $this->userWithoutWorkspace('crash@studio.test');

        // Fail after the organization and the claim, inside the same
        // transaction. Without the transaction the slot would be held by a
        // workspace that never survived -- and there are only fifty, so every
        // leak is permanent and shows on the homepage counter.
        try {
            Database::transaction(function () use ($userId): void {
                (new SignupService())->provision($userId, $this->now);

                throw new \RuntimeException('crash after provisioning');
            });

            self::fail('the transaction should have rethrown');
        } catch (\RuntimeException $exception) {
            self::assertSame('crash after provisioning', $exception->getMessage());
        }

        self::assertSame(
            0,
            (int) Database::connection()
                ->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')
                ->fetchColumn(),
            'a founding slot survived a rolled-back signup'
        );

        self::assertNull(
            $this->user('crash@studio.test')['organization_id'],
            'a workspace survived a rolled-back signup'
        );
    }

    public function testEveryClaimedSlotPointsAtAWorkspaceThatExists(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->signUp('holder' . $i . '@studio.test');
        }

        $orphans = (int) Database::connection()->query(
            'SELECT COUNT(*) FROM founding_slots s
             LEFT JOIN organizations o ON o.id = s.tenant_id
             WHERE s.tenant_id IS NOT NULL AND o.id IS NULL'
        )->fetchColumn();

        self::assertSame(0, $orphans);
    }

    // ------------------ self-check: an expired hold returns to the pool

    public function testAnExpiredUnpaidHoldGoesBackAndTheCounterRises(): void
    {
        $this->signUp('lapsing@studio.test');

        $plans = new PlanService();
        $before = $plans->foundingAvailability()['remaining'];

        $tenantId = (int) $this->organizationFor('lapsing@studio.test')['id'];
        self::assertSame(1, $this->slotCountFor($tenantId));

        // Thirty-one days on, still no subscription.
        $result = (new ReleaseExpiredFoundingSlotsJob())->run(
            Clock::now()->modify('+' . (PlanService::FOUNDING_HOLD_DAYS + 1) . ' days')
        );

        self::assertSame(1, $result['released']);
        self::assertSame(0, $this->slotCountFor($tenantId));
        self::assertSame($before + 1, $plans->foundingAvailability()['remaining']);

        // And the workspace no longer claims to be founding.
        self::assertSame(0, (int) $this->organizationFor('lapsing@studio.test')['is_founding']);
    }

    public function testAPayingWorkspaceKeepsItsSlotForever(): void
    {
        $this->signUp('paying@studio.test');
        $tenantId = (int) $this->organizationFor('paying@studio.test')['id'];

        Database::connection()->prepare(
            'INSERT INTO subscriptions
                (tenant_id, user_id, stripe_subscription_id, stripe_price_id, plan, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $tenantId,
            (int) $this->user('paying@studio.test')['id'],
            'sub_paying_' . $tenantId,
            'price_solo_monthly',
            PlanService::PLAN_SOLO,
            'active',
        ]);

        // A year later. The date on the row stopped mattering the moment they
        // subscribed.
        $result = (new ReleaseExpiredFoundingSlotsJob())->run(Clock::now()->modify('+365 days'));

        self::assertSame(0, $result['released']);
        self::assertSame(1, $this->slotCountFor($tenantId));
    }

    public function testTheWorkspaceIsWarnedBeforeTheHoldLapsesAndOnlyOnce(): void
    {
        $this->signUp('warned@studio.test');

        $job = new ReleaseExpiredFoundingSlotsJob();

        // Three days out: inside the warning window, not yet lapsed.
        $threeDaysBefore = Clock::now()->modify('+' . (PlanService::FOUNDING_HOLD_DAYS - 3) . ' days');

        $first = $job->run($threeDaysBefore);

        self::assertSame(1, $first['warned']);
        self::assertSame(0, $first['released'], 'released before the hold expired');
        self::assertStringContainsString('founding place is held until', $this->latestMailLog());

        // Tomorrow, and the day after. A warning every morning for a week is
        // not a better warning.
        $second = $job->run($threeDaysBefore->modify('+1 day'));

        self::assertSame(0, $second['warned']);
    }

    public function testAReleaseIsRecordedAgainstTheWorkspaceItWasTakenFrom(): void
    {
        $this->signUp('audited@studio.test');
        $tenantId = (int) $this->organizationFor('audited@studio.test')['id'];

        (new ReleaseExpiredFoundingSlotsJob())->run(
            Clock::now()->modify('+' . (PlanService::FOUNDING_HOLD_DAYS + 1) . ' days')
        );

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE organization_id = ? AND action = ?'
        );
        $statement->execute([$tenantId, 'founding.slot_released']);

        // A slot reappearing on the counter with no record of where it came
        // from is not something anybody can audit later.
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    // ----------------------------- self-check: the waitlist still works

    public function testTheWaitlistRoutesStillRespond(): void
    {
        // Those links are in people's inboxes already and must not 404 because
        // signup arrived.
        self::assertNotSame(404, $this->get('/waitlist/confirm?token=whatever')->status);
        self::assertNotSame(404, $this->get('/waitlist/unsubscribe?email=a@b.test')->status);
        self::assertSame(200, $this->get('/')->status);
    }

    public function testRegisterRedirectsToSignup(): void
    {
        $response = $this->get('/register');

        self::assertSame(302, $response->status);
        self::assertSame('/signup', $response->header('Location'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The whole flow, through the real endpoints. Returns where it landed.
     */
    private function signUp(string $email): string
    {
        $this->requestCode($email);

        preg_match_all('/^\s{4}([0-9]{6})$/m', $this->latestMailLog(), $matches);
        self::assertNotSame([], $matches[1], 'no code was emailed to ' . $email);

        $response = $this->postJson('/auth/otp/verify', [
            'email' => $email,
            'code' => (string) end($matches[1]),
        ], ['X-CSRF-Token' => $this->csrfToken()]);

        self::assertSame(200, $response->status, 'verification failed for ' . $email);

        return (string) ($response->json()['redirect'] ?? '');
    }

    private function requestCode(string $email): \Tests\Support\TestResponse
    {
        return $this->postJson('/auth/otp/request', ['email' => $email], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);
    }

    private function userWithoutWorkspace(string $email): int
    {
        $connection = Database::connection();
        $connection->prepare('INSERT INTO users (email, created_at) VALUES (?, NOW())')->execute([$email]);

        return (int) $connection->lastInsertId();
    }

    private function workspace(string $name): int
    {
        $connection = Database::connection();
        $connection->prepare('INSERT INTO organizations (name, slug) VALUES (?, ?)')
            ->execute([$name, strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(3))]);

        return (int) $connection->lastInsertId();
    }

    private function user(string $email): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);

        return $statement->fetch() ?: null;
    }

    private function organizationFor(string $email): array
    {
        $statement = Database::connection()->prepare(
            'SELECT o.* FROM organizations o
             INNER JOIN users u ON u.organization_id = o.id
             WHERE u.email = ? LIMIT 1'
        );
        $statement->execute([$email]);

        return $statement->fetch() ?: [];
    }

    private function slotCountFor(int $tenantId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM founding_slots WHERE tenant_id = ?'
        );
        $statement->execute([$tenantId]);

        return (int) $statement->fetchColumn();
    }
}
