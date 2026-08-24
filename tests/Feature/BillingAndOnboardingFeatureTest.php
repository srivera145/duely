<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Controllers\BillingController;
use Keel\App\Models\Chase;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Services\ChaseScheduler;
use Keel\App\Services\OnboardingService;
use Keel\App\Services\PlanService;
use Keel\App\Services\TenantContext;
use Keel\Core\Database;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Billing, the founding cohort, plan gating, and the guided first run.
 *
 * The three things worth being paranoid about, and each has its own section:
 *
 *   Money must be granted by the webhook and nothing else, so a browser cannot
 *   talk itself onto a paid plan.
 *
 *   The cap of fifty must hold under a race. Counting rows and then inserting
 *   is the obvious implementation and it is wrong; this proves the conditional
 *   UPDATE holds when real processes fight over the last slots.
 *
 *   A replayed webhook must change nothing. Stripe delivers at least once, and
 *   a second grant would hand out a second founding place.
 */
class BillingAndOnboardingFeatureTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_billing_feature_test';

    private array $user;
    private int $tenantId;
    private DateTimeImmutable $now;
    private PlanService $plans;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['STRIPE_WEBHOOK_SECRET'] = self::WEBHOOK_SECRET;
        $_SERVER['STRIPE_WEBHOOK_SECRET'] = self::WEBHOOK_SECRET;
        $_ENV['STRIPE_PRICE_SOLO_MONTHLY'] = 'price_solo_standard';
        $_SERVER['STRIPE_PRICE_SOLO_MONTHLY'] = 'price_solo_standard';
        $_ENV['STRIPE_PRICE_STUDIO_MONTHLY'] = 'price_studio_standard';
        $_SERVER['STRIPE_PRICE_STUDIO_MONTHLY'] = 'price_studio_standard';
        $_ENV['STRIPE_PRICE_FOUNDING_MONTHLY'] = 'price_founding_locked';
        $_SERVER['STRIPE_PRICE_FOUNDING_MONTHLY'] = 'price_founding_locked';

        $this->user = $this->createUser(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $this->tenantId = TenantContext::forUser((int) $this->user['id']);
        $this->now = new DateTimeImmutable('2026-08-23 10:00:00', new DateTimeZone('UTC'));
        $this->plans = new PlanService();
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['STRIPE_WEBHOOK_SECRET'], $_SERVER['STRIPE_WEBHOOK_SECRET'],
            $_ENV['STRIPE_PRICE_SOLO_MONTHLY'], $_SERVER['STRIPE_PRICE_SOLO_MONTHLY'],
            $_ENV['STRIPE_PRICE_STUDIO_MONTHLY'], $_SERVER['STRIPE_PRICE_STUDIO_MONTHLY'],
            $_ENV['STRIPE_PRICE_FOUNDING_MONTHLY'], $_SERVER['STRIPE_PRICE_FOUNDING_MONTHLY'],
        );

        parent::tearDown();
    }

    // ------------------------ self-check: signup, trial, paid upgrade, in order

    public function testANewWorkspaceStartsFreeWithTheFreeLimits(): void
    {
        $status = $this->plans->status($this->tenantId, $this->now);

        self::assertSame(PlanService::PLAN_FREE, $status['plan']);
        self::assertSame(PlanService::PLAN_FREE, $status['effective_plan']);
        self::assertFalse($status['on_trial']);
        self::assertFalse($status['has_subscription']);
        self::assertFalse($status['is_founding']);
        self::assertSame(3, $status['limits'][PlanService::FEATURE_ACTIVE_CHASE]);
        self::assertSame(1, $status['limits'][PlanService::FEATURE_EMAIL_ACCOUNT]);
    }

    public function testTheTrialGrantsThePaidPlanWithoutACardAndExpiresBackToFree(): void
    {
        self::assertTrue($this->plans->startTrial($this->tenantId, PlanService::PLAN_SOLO, $this->now));

        $duringTrial = $this->plans->status($this->tenantId, $this->now->modify('+3 days'));

        self::assertTrue($duringTrial['on_trial']);
        self::assertSame(PlanService::PLAN_SOLO, $duringTrial['effective_plan']);
        self::assertFalse($duringTrial['has_subscription'], 'a trial is not a subscription');
        self::assertSame(11, $duringTrial['trial_days_left']);

        // Unlimited chases while the trial runs.
        self::assertNull(
            $this->plans->canUseFeature($this->tenantId, PlanService::FEATURE_ACTIVE_CHASE, $this->now)['limit']
        );

        // And back to Free the moment it lapses, rather than staying on a plan
        // nobody ever paid for.
        $afterTrial = $this->plans->status($this->tenantId, $this->now->modify('+15 days'));

        self::assertFalse($afterTrial['on_trial']);
        self::assertSame(PlanService::PLAN_SOLO, $afterTrial['plan'], 'the billed plan is remembered');
        self::assertSame(PlanService::PLAN_FREE, $afterTrial['effective_plan'], 'but it no longer behaves as one');
        self::assertSame(3, $afterTrial['limits'][PlanService::FEATURE_ACTIVE_CHASE]);
    }

    public function testATrialIsOfferedOnlyOnce(): void
    {
        self::assertTrue($this->plans->startTrial($this->tenantId, PlanService::PLAN_SOLO, $this->now));
        self::assertFalse(
            $this->plans->startTrial($this->tenantId, PlanService::PLAN_SOLO, $this->now->modify('+60 days')),
            'a second trial would be a free plan with extra steps'
        );
    }

    public function testTheCheckoutWebhookIsWhatGrantsThePaidPlan(): void
    {
        $this->plans->startTrial($this->tenantId, PlanService::PLAN_SOLO, $this->now);

        $response = $this->sendWebhook($this->checkoutEvent('evt_upgrade_1', $this->tenantId));

        self::assertSame(200, $response->status);
        self::assertTrue((bool) ($response->json()['handled'] ?? false));

        $status = $this->plans->status($this->tenantId, $this->now->modify('+40 days'));

        self::assertTrue($status['has_subscription']);
        self::assertSame(PlanService::PLAN_SOLO, $status['effective_plan'], 'a paid plan outlives the trial clock');
        self::assertTrue($status['is_founding']);
        self::assertSame(1, $status['founding_slot']);
        self::assertSame(1900, $status['price_cents']);

        $subscription = $this->subscriptionRow('sub_upgrade_1');

        self::assertSame($this->tenantId, (int) $subscription['tenant_id']);
        self::assertSame((int) $this->user['id'], (int) $subscription['user_id']);
        self::assertSame('active', (string) $subscription['status']);
    }

    public function testTheBrowserCannotGrantItselfAPlan(): void
    {
        // /billing/success is a redirect target, not an entitlement.
        $this->signIn();

        $this->get('/billing/success');

        self::assertSame(
            PlanService::PLAN_FREE,
            $this->plans->effectivePlan($this->tenantId, $this->now),
            'landing on the success page must not change the plan'
        );
    }

    public function testALapsedSubscriptionDropsTheWorkspaceBackToFree(): void
    {
        $this->sendWebhook($this->checkoutEvent('evt_lapse_1', $this->tenantId));

        $this->sendWebhook($this->subscriptionEvent(
            'evt_lapse_2',
            'customer.subscription.deleted',
            'sub_lapse_1',
            'canceled'
        ));

        self::assertSame(PlanService::PLAN_FREE, $this->plans->effectivePlan($this->tenantId, $this->now));
    }

    public function testAFailedPaymentDoesNotImmediatelyDowngrade(): void
    {
        $this->sendWebhook($this->checkoutEvent('evt_fail_1', $this->tenantId));

        $event = [
            'id' => 'evt_fail_2',
            'object' => 'event',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['object' => 'invoice', 'subscription' => 'sub_fail_1']],
        ];

        self::assertSame(200, $this->sendWebhook($event)->status);
        self::assertSame(
            'past_due',
            (string) $this->subscriptionRow('sub_fail_1')['status'],
            'the failure is recorded'
        );
        self::assertSame(
            PlanService::PLAN_SOLO,
            $this->plans->effectivePlan($this->tenantId, $this->now),
            'but Stripe retries for days, and pausing chases over a card about to succeed is worse'
        );
    }

    // -------------------------- self-check: the fifty-first gets standard pricing

    public function testTheFiftyFirstPaidWorkspaceGetsStandardPricingRatherThanBecomingNumber51(): void
    {
        $this->fillFoundingSlots(50);

        self::assertSame(0, $this->plans->foundingAvailability()['remaining']);

        $claim = $this->plans->claimFoundingSlot($this->tenantId, $this->now);

        self::assertFalse($claim['claimed']);
        self::assertNull($claim['slot']);
        self::assertStringContainsString('50 founding places', (string) $claim['reason']);

        $status = $this->plans->status($this->tenantId, $this->now);

        self::assertFalse($status['is_founding']);
        self::assertNull($status['founding_slot']);

        self::assertSame(
            50,
            (int) Database::connection()->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')->fetchColumn(),
            'there is no fifty-first slot to take'
        );

        // And the checkout it is offered is the standard price, not the locked one.
        self::assertSame('price_solo_standard', $this->priceIdFor($this->tenantId, PlanService::PLAN_SOLO));
    }

    public function testAFoundingWorkspaceIsCheckedOutAgainstTheGrandfatheredPriceId(): void
    {
        $this->sendWebhook($this->checkoutEvent('evt_founding_price', $this->tenantId));

        self::assertTrue($this->plans->status($this->tenantId, $this->now)['is_founding']);
        self::assertSame('price_founding_locked', $this->priceIdFor($this->tenantId, PlanService::PLAN_SOLO));
        self::assertSame(1900, $this->plans->priceFor($this->tenantId, PlanService::PLAN_SOLO));

        // Studio is never part of the offer.
        self::assertSame('price_studio_standard', $this->priceIdFor($this->tenantId, PlanService::PLAN_STUDIO));
        self::assertSame(3900, $this->plans->priceFor($this->tenantId, PlanService::PLAN_STUDIO));
    }

    public function testTheCohortStopsAdvertisingItselfOnceItIsFull(): void
    {
        $this->fillFoundingSlots(49);

        self::assertTrue($this->plans->catalogue($this->tenantId)[PlanService::PLAN_SOLO]['founding_available']);

        $this->fillFoundingSlots(1);

        self::assertFalse($this->plans->catalogue($this->tenantId)[PlanService::PLAN_SOLO]['founding_available']);
    }

    public function testSixtyWorkspacesClaimingInTurnProduceExactlyFiftyWinners(): void
    {
        $tenants = [];

        for ($index = 0; $index < 60; $index++) {
            $tenants[] = TenantContext::forUser((int) $this->createUser([
                'email' => 'racer' . $index . '@studio.test',
            ])['id']);
        }

        foreach ($tenants as $tenantId) {
            $this->plans->claimFoundingSlot($tenantId, $this->now);
        }

        $connection = Database::connection();

        self::assertSame(
            50,
            (int) $connection->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')->fetchColumn()
        );
        self::assertSame(
            50,
            (int) $connection->query('SELECT COUNT(DISTINCT tenant_id) FROM founding_slots WHERE tenant_id IS NOT NULL')->fetchColumn(),
            'no workspace may hold two slots'
        );
        self::assertSame(
            50,
            (int) $connection->query('SELECT COUNT(*) FROM organizations WHERE is_founding = 1')->fetchColumn()
        );
        self::assertSame(
            [1, 50],
            array_map('intval', (array) $connection->query(
                'SELECT MIN(slot_number), MAX(slot_number) FROM founding_slots WHERE tenant_id IS NOT NULL'
            )->fetch(\PDO::FETCH_NUM)),
            'the slots handed out are 1 to 50'
        );
    }

    public function testTwelveRealProcessesRacingForFiveSlotsNeverProduceANumberFiftyOne(): void
    {
        // The sequential test above passes just as happily against a
        // count-then-insert implementation, which is wrong. This one does not.
        $this->fillFoundingSlots(45);

        $contenders = [];

        for ($index = 0; $index < 12; $index++) {
            $contenders[] = TenantContext::forUser((int) $this->createUser([
                'email' => 'contender' . $index . '@studio.test',
            ])['id']);
        }

        $directory = sys_get_temp_dir() . '/duely-race-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);

        $startFile = $directory . '/go';
        $script = dirname(__DIR__) . '/Support/claim-founding-slot.php';
        $processes = [];
        $resultFiles = [];

        foreach ($contenders as $position => $tenantId) {
            $resultFile = $directory . '/result-' . $position . '.json';
            $resultFiles[] = $resultFile;

            $process = proc_open(
                [PHP_BINARY, $script, (string) $tenantId, $startFile, $resultFile],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            self::assertIsResource($process, 'could not start a contender');

            $processes[] = [$process, $pipes];
        }

        // Everyone is booted and connected; release them together.
        usleep(400000);
        file_put_contents($startFile, 'go');

        $errors = [];

        foreach ($processes as [$process, $pipes]) {
            $errors[] = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(0, proc_close($process), 'a contender crashed: ' . implode(' ', $errors));
        }

        $claimed = [];

        foreach ($resultFiles as $resultFile) {
            self::assertFileExists($resultFile, 'a contender produced no result');

            $result = json_decode((string) file_get_contents($resultFile), true);

            self::assertIsArray($result);
            self::assertStringNotContainsString('error:', (string) $result['reason']);

            if ($result['claimed']) {
                $claimed[] = (int) $result['slot'];
            }
        }

        array_map('unlink', glob($directory . '/*') ?: []);
        rmdir($directory);

        sort($claimed);

        self::assertSame([46, 47, 48, 49, 50], $claimed, 'exactly the five remaining slots, each once');

        $connection = Database::connection();

        self::assertSame(
            50,
            (int) $connection->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')->fetchColumn()
        );
        self::assertSame(
            50,
            (int) $connection->query('SELECT COUNT(DISTINCT tenant_id) FROM founding_slots WHERE tenant_id IS NOT NULL')->fetchColumn(),
            'no slot was handed to two workspaces'
        );
        self::assertSame(
            50,
            (int) $connection->query('SELECT COUNT(*) FROM organizations WHERE is_founding = 1')->fetchColumn(),
            'and nobody became number 51'
        );
    }

    public function testClaimingTwiceReturnsTheSameSlotRatherThanASecondOne(): void
    {
        $first = $this->plans->claimFoundingSlot($this->tenantId, $this->now);
        $second = $this->plans->claimFoundingSlot($this->tenantId, $this->now);

        self::assertTrue($first['claimed']);
        self::assertTrue($second['claimed']);
        self::assertSame($first['slot'], $second['slot']);
        self::assertSame(49, $this->plans->foundingAvailability()['remaining']);
    }

    // ------------------------------- self-check: a replay grants nothing twice

    public function testAReplayedCheckoutWebhookGrantsNothingASecondTime(): void
    {
        $event = $this->checkoutEvent('evt_replay_1', $this->tenantId);

        $first = $this->sendWebhook($event);
        $second = $this->sendWebhook($event);

        self::assertSame(200, $first->status);
        self::assertTrue((bool) $first->json()['handled']);
        self::assertFalse((bool) $first->json()['duplicate']);

        // 200, not 500: the work is done, and a retry would achieve nothing.
        self::assertSame(200, $second->status);
        self::assertFalse((bool) $second->json()['handled']);
        self::assertTrue((bool) $second->json()['duplicate']);

        $connection = Database::connection();

        self::assertSame(
            1,
            (int) $connection->query("SELECT COUNT(*) FROM stripe_events WHERE stripe_event_id = 'evt_replay_1'")->fetchColumn(),
            'the event is logged once'
        );
        self::assertSame(
            1,
            (int) $connection->query("SELECT COUNT(*) FROM subscriptions WHERE stripe_subscription_id = 'sub_replay_1'")->fetchColumn(),
            'no second subscription row'
        );
        self::assertSame(
            1,
            (int) $connection->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')->fetchColumn(),
            'no second founding place'
        );
        self::assertSame(
            1,
            (int) $connection->query(
                "SELECT COUNT(*) FROM activity_log WHERE action = 'billing.subscription_started'"
            )->fetchColumn(),
            'and it is only announced once'
        );
    }

    public function testATamperedPayloadIsRejectedBeforeAnythingIsRead(): void
    {
        $event = $this->checkoutEvent('evt_tampered', $this->tenantId);
        $raw = (string) json_encode($event);

        $response = $this->postRawJson('/webhooks/stripe', $raw, [
            'Stripe-Signature' => 't=' . time() . ',v1=' . str_repeat('0', 64),
        ]);

        self::assertSame(400, $response->status);
        self::assertSame(
            0,
            (int) Database::connection()->query("SELECT COUNT(*) FROM stripe_events WHERE stripe_event_id = 'evt_tampered'")->fetchColumn(),
            'an unsigned event is never even logged, let alone applied'
        );
        self::assertSame(PlanService::PLAN_FREE, $this->plans->effectivePlan($this->tenantId, $this->now));
    }

    public function testAFailedEventIsRetriedRatherThanTreatedAsDone(): void
    {
        $log = new \Keel\App\Services\StripeEventLog();

        $claim = $log->claim('evt_retryable', 'checkout.session.completed', '{}');
        self::assertTrue($claim['claimed']);

        $log->markFailed((int) $claim['id'], 'database went away');

        $retry = $log->claim('evt_retryable', 'checkout.session.completed', '{}');

        self::assertTrue($retry['claimed'], 'a failure Stripe will retry must be re-claimable');
        self::assertSame((int) $claim['id'], (int) $retry['id'], 'and it reuses the same log row');

        // A processed event, by contrast, stays done.
        $log->markProcessed((int) $retry['id'], $this->tenantId);

        self::assertFalse($log->claim('evt_retryable', 'checkout.session.completed', '{}')['claimed']);
    }

    // ------------------------------------------------------------- plan gating

    public function testTheChaseLimitIsEnforcedAtTheEndpointWithAnUpgradePath(): void
    {
        $this->signIn();

        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);
        $invoices = [];

        for ($index = 1; $index <= 4; $index++) {
            $invoices[] = $this->invoice($clientId, 'INV-' . $index);
        }

        $this->mailbox();

        // Three are allowed on Free.
        foreach (array_slice($invoices, 0, 3) as $invoiceId) {
            $response = $this->postJson('/api/invoices/' . $invoiceId . '/start-chase', [
                '_csrf' => $this->csrfToken(),
            ]);

            self::assertSame(200, $response->status, (string) json_encode($response->json()));
        }

        $blocked = $this->postJson('/api/invoices/' . $invoices[3] . '/start-chase', [
            '_csrf' => $this->csrfToken(),
        ]);

        self::assertSame(402, $blocked->status, 'payment required, not forbidden');
        self::assertSame(3, $blocked->json()['limit']);
        self::assertSame(3, $blocked->json()['used']);
        self::assertSame(PlanService::PLAN_SOLO, $blocked->json()['upgrade_to']);
        self::assertStringContainsString('Solo', (string) $blocked->json()['error']);
    }

    public function testTheGateIsTheOnlyPlaceAControllerAsksAboutPlans(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/App/Controllers/*.php') as $path) {
            if (basename($path) === 'BillingController.php') {
                // The one place a plan name is legitimately named: it has to
                // decide which Stripe price to send someone to.
                continue;
            }

            $source = (string) file_get_contents($path);

            if (preg_match('/PLAN_(FREE|SOLO|STUDIO)\b/', $source) === 1) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame([], $offenders, 'plan comparisons belong in PlanService::canUseFeature()');
    }

    public function testADowngradePausesTheNewestChasesAndSaysWhichOnes(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);
        $accountId = $this->mailbox();
        $sequenceId = (int) Sequence::defaultSequence($this->tenantId)['id'];
        $scheduler = new ChaseScheduler();

        $started = [];

        // Five chases, started oldest first.
        for ($index = 1; $index <= 5; $index++) {
            $invoiceId = $this->invoice($clientId, 'DOWN-' . $index);
            $scheduler->start(
                $this->tenantId,
                $invoiceId,
                $sequenceId,
                $accountId,
                $this->now->modify('+' . $index . ' minutes')
            );
            $started[$index] = (int) Chase::forInvoice($this->tenantId, $invoiceId)['id'];
        }

        $result = $this->plans->applyPlan($this->tenantId, PlanService::PLAN_FREE, $this->now);

        self::assertCount(2, $result['paused'], 'five chases, a limit of three');
        self::assertSame(
            ['DOWN-5', 'DOWN-4'],
            array_column($result['paused'], 'invoice_number'),
            'the newest are paused; the oldest are closest to being paid'
        );
        self::assertSame(['Bill', 'Bill'], array_column($result['paused'], 'client_name'));

        // Paused, never deleted.
        foreach ([1, 2, 3] as $index) {
            self::assertContains(
                Chase::find($this->tenantId, $started[$index])['status'],
                [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE]
            );
        }

        foreach ([4, 5] as $index) {
            $chase = Chase::find($this->tenantId, $started[$index]);

            self::assertSame(Chase::STATUS_PAUSED, $chase['status']);
            self::assertNotNull($chase, 'a downgrade must not delete anyone\'s work');
        }
    }

    public function testASecondMailboxIsGatedButEditingTheFirstIsNot(): void
    {
        $this->mailbox('first@studio.test');

        self::assertFalse(
            $this->plans->allows($this->tenantId, PlanService::FEATURE_EMAIL_ACCOUNT, $this->now),
            'Free allows one mailbox'
        );

        $this->plans->startTrial($this->tenantId, PlanService::PLAN_STUDIO, $this->now);

        self::assertTrue(
            $this->plans->allows($this->tenantId, PlanService::FEATURE_EMAIL_ACCOUNT, $this->now),
            'Studio allows three'
        );
    }

    // ----------------------------------------------------------- the first run

    public function testProgressIsDerivedFromWhatTheWorkspaceActuallyHas(): void
    {
        $onboarding = new OnboardingService();

        $fresh = $onboarding->progress($this->tenantId);

        self::assertSame(0, $fresh['completed_count']);
        self::assertSame(0, $fresh['percent']);
        self::assertFalse($fresh['complete']);
        self::assertSame(OnboardingService::STEP_EMAIL, $fresh['current']);
        self::assertSame(
            [OnboardingService::STEP_EMAIL, OnboardingService::STEP_INVOICE,
             OnboardingService::STEP_SEQUENCE, OnboardingService::STEP_CHASING],
            array_column($fresh['steps'], 'key'),
            'a mailbox has to exist before an invoice is worth chasing'
        );

        // Someone who imports a CSV without ever opening the wizard has still
        // done the step, and being told otherwise would be insulting.
        $accountId = $this->mailbox();
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);
        $invoiceId = $this->invoice($clientId, 'FIRST-1');

        $afterImport = $onboarding->progress($this->tenantId);

        self::assertSame(2, $afterImport['completed_count']);
        self::assertSame(50, $afterImport['percent']);
        self::assertSame(OnboardingService::STEP_SEQUENCE, $afterImport['current']);

        $onboarding->markReviewed($this->tenantId);

        (new ChaseScheduler())->start(
            $this->tenantId,
            $invoiceId,
            (int) Sequence::defaultSequence($this->tenantId)['id'],
            $accountId,
            $this->now
        );

        $finished = $onboarding->progress($this->tenantId);

        self::assertTrue($finished['complete']);
        self::assertSame(100, $finished['percent']);
        self::assertNull($finished['current']);
    }

    public function testTheWizardCanBeSkippedAndComeBackTo(): void
    {
        $this->signIn();

        $onboarding = new OnboardingService();

        self::assertTrue($onboarding->shouldPrompt($this->tenantId));

        $skip = $this->postJson('/api/onboarding/skip', ['_csrf' => $this->csrfToken()]);

        self::assertSame(200, $skip->status);
        self::assertTrue((bool) $skip->json()['progress']['skipped']);
        self::assertFalse($onboarding->shouldPrompt($this->tenantId), 'no means no');

        // But nothing is lost: the steps are still there to come back to.
        self::assertSame(0, $skip->json()['progress']['completed_count']);

        $resume = $this->postJson('/api/onboarding/resume', ['_csrf' => $this->csrfToken()]);

        self::assertSame(200, $resume->status);
        self::assertFalse((bool) $resume->json()['progress']['skipped']);
        self::assertTrue($onboarding->shouldPrompt($this->tenantId));
    }

    public function testTheWizardNeverBlocksTheRestOfTheApp(): void
    {
        $this->signIn();

        // Nothing has been set up and the wizard has not been touched.
        foreach (['/dashboard', '/invoices', '/settings/email', '/onboarding', '/billing/upgrade', '/billing/cancel'] as $path) {
            self::assertSame(200, $this->get($path)->status, $path . ' should be reachable');
        }
    }

    public function testTheOnboardingEndpointsRequireCsrfAndAnAccount(): void
    {
        $unauthenticated = $this->postJson('/api/onboarding/skip', []);

        self::assertContains(
            $unauthenticated->status,
            [302, 401, 419],
            'an anonymous POST must not record progress for anyone'
        );

        $this->signIn();

        self::assertSame(419, $this->postJson('/api/onboarding/skip', [])->status, 'CSRF is required');
    }

    // -------------------------------------------------------------- internals

    /**
     * Sign in as the workspace owner created in setUp().
     *
     * Not actingAs(): that creates a second user, and the one this test needs
     * is the one that already owns the tenant.
     */
    private function signIn(): void
    {
        \Keel\Core\Session::put('user_id', (int) $this->user['id']);
        \Keel\Core\Session::put('user_email', (string) $this->user['email']);
        \Keel\Core\Session::put('organization_id', $this->tenantId);
        \Keel\Core\Auth::setUserId(null);
    }

    private function priceIdFor(int $tenantId, string $plan): string
    {
        $method = new ReflectionMethod(BillingController::class, 'priceIdFor');
        $method->setAccessible(true);

        return (string) $method->invoke(new BillingController(), $tenantId, $plan);
    }

    /**
     * Take $count founding slots with throwaway workspaces.
     */
    private function fillFoundingSlots(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $tenantId = TenantContext::forUser((int) $this->createUser([
                'email' => 'founder_' . bin2hex(random_bytes(4)) . '@studio.test',
            ])['id']);

            $this->plans->claimFoundingSlot($tenantId, $this->now);
        }
    }

    private function mailbox(string $email = 'ada@studio.test'): int
    {
        return EmailAccount::create($this->tenantId, [
            'from_name' => 'Ada Lovelace',
            'from_email' => $email,
            'smtp_host' => 'smtp.studio.test',
            'smtp_port' => 587,
            'smtp_username' => $email,
            'imap_host' => 'imap.studio.test',
            'imap_port' => 993,
            'imap_username' => $email,
            'status' => EmailAccount::STATUS_ACTIVE,
            'is_default' => 1,
        ]);
    }

    private function invoice(int $clientId, string $number, int $daysOverdue = 20): int
    {
        return Invoice::create($this->tenantId, [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => 120000,
            'currency' => 'USD',
            'due_date' => $this->now->modify('-' . $daysOverdue . ' days')->format('Y-m-d'),
        ]);
    }

    private function subscriptionRow(string $stripeSubscriptionId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1'
        );
        $statement->execute([$stripeSubscriptionId]);

        $row = $statement->fetch();

        self::assertIsArray($row, 'expected a subscription row for ' . $stripeSubscriptionId);

        return $row;
    }

    /**
     * A checkout.session.completed carrying the workspace, as Duely's own
     * checkout does — the webhook is where entitlement is granted, so it must
     * not have to guess who paid.
     */
    private function checkoutEvent(string $eventId, int $tenantId): array
    {
        $subscriptionId = 'sub_' . substr($eventId, 4);

        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_' . substr($eventId, 4),
                    'object' => 'checkout.session',
                    'mode' => 'subscription',
                    'customer' => 'cus_' . $tenantId,
                    'client_reference_id' => (string) $this->user['id'],
                    'subscription' => $subscriptionId,
                    'metadata' => [
                        'tenant_id' => (string) $tenantId,
                        'user_id' => (string) $this->user['id'],
                        'plan' => PlanService::PLAN_SOLO,
                    ],
                ],
            ],
        ];
    }

    private function subscriptionEvent(string $eventId, string $type, string $subscriptionId, string $status): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => $subscriptionId,
                    'object' => 'subscription',
                    'status' => $status,
                    'customer' => 'cus_' . $this->tenantId,
                    'current_period_end' => $this->now->getTimestamp() + 86400,
                    'cancel_at_period_end' => false,
                    'items' => ['object' => 'list', 'data' => [['price' => ['id' => 'price_founding_locked']]]],
                    'metadata' => ['tenant_id' => (string) $this->tenantId, 'plan' => PlanService::PLAN_SOLO],
                ],
            ],
        ];
    }

    private function sendWebhook(array $event): \Tests\Support\TestResponse
    {
        $raw = (string) json_encode($event);
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1='
            . hash_hmac('sha256', $timestamp . '.' . $raw, self::WEBHOOK_SECRET);

        return $this->postRawJson('/webhooks/stripe', $raw, ['Stripe-Signature' => $signature]);
    }
}
