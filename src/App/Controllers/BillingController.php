<?php

namespace Keel\App\Controllers;

use Keel\App\Models\User;
use Keel\App\Services\BillingService;
use Keel\App\Services\PlanService;
use Keel\App\Services\TenantContext;
use Keel\Core\Activity;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Throwable;

/**
 * Plans, trials, and checkout.
 *
 * Billing is per workspace, not per user: a Studio team shares one
 * subscription, so a seat holder must not be able to buy a second one.
 */
class BillingController extends Controller
{
    public function __construct(private readonly PlanService $plans = new PlanService())
    {
    }

    /**
     * GET /billing/upgrade
     */
    public function showPlans(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->view('billing.plans', [
            'title' => 'Plans - Duely',
            'metaDescription' => 'Duely pricing: free to start, $19 a month for the first fifty accounts.',
            'catalogue' => $this->plans->catalogue($tenantId),
            'status' => $this->plans->status($tenantId),
            'founding' => $this->plans->foundingAvailability(),
            'publishableKey' => Env::get('STRIPE_PUBLISHABLE_KEY', ''),
            'stripeConfigured' => trim((string) Env::get('STRIPE_SECRET_KEY', '')) !== '',
        ]);
    }

    /**
     * POST /api/billing/trial — start the 14-day trial. No card.
     */
    public function startTrial(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $plan = (string) $request->input('plan', PlanService::PLAN_SOLO);

        if (!$this->plans->startTrial($tenantId, $plan)) {
            $this->json([
                'error' => 'This workspace has already used its trial.',
                'status' => $this->plans->status($tenantId),
            ], 409);
        }

        Activity::log('billing.trial_started', 'Organization', $tenantId, ['plan' => $plan]);

        $this->json(['status' => $this->plans->status($tenantId)]);
    }

    /**
     * POST /billing/checkout
     */
    public function checkout(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $plan = strtolower(trim((string) $request->input('plan', '')));

        if (!in_array($plan, [PlanService::PLAN_SOLO, PlanService::PLAN_STUDIO], true)) {
            $this->redirect('/billing/upgrade?error=invalid_plan');
        }

        $priceId = $this->priceIdFor($tenantId, $plan);

        if ($priceId === '') {
            $this->redirect('/billing/upgrade?error=price_not_configured');
        }

        try {
            $session = (new BillingService())->createCheckoutSession(
                $this->currentUser(),
                $priceId,
                // Carried through to the webhook, which is where the plan is
                // actually granted — the browser never decides entitlement.
                [
                    'tenant_id' => (string) $tenantId,
                    'plan' => $plan,
                ]
            );
        } catch (Throwable $exception) {
            error_log('[Duely] Checkout session failed: ' . $exception->getMessage());
            $this->redirect('/billing/upgrade?error=checkout_failed');
        }

        $this->redirect((string) $session->url);
    }

    /**
     * GET /billing/success
     */
    public function success(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->view('billing.success', [
            'title' => 'Welcome aboard - Duely',
            'status' => $this->plans->status($tenantId),
        ]);
    }

    public function cancel(Request $request): void
    {
        $this->view('billing.cancel', ['title' => 'Checkout cancelled - Duely']);
    }

    /**
     * POST /billing/portal
     */
    public function portal(Request $request): void
    {
        TenantContext::requireId();

        try {
            $session = (new BillingService())->createPortalSession($this->currentUser());
        } catch (Throwable $exception) {
            error_log('[Duely] Billing portal failed: ' . $exception->getMessage());
            $this->redirect('/billing/upgrade?error=portal_failed');
        }

        $this->redirect((string) $session->url);
    }

    /**
     * GET /api/billing/status
     */
    public function status(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->json([
            'status' => $this->plans->status($tenantId),
            'founding' => $this->plans->foundingAvailability(),
            'catalogue' => $this->plans->catalogue($tenantId),
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * The Stripe price to charge.
     *
     * A workspace holding a founding place — or one about to claim the last of
     * them — is billed against the grandfathered price id for whichever plan it
     * is buying, so a later increase to the standard price leaves it alone.
     * This is deliberately not Solo-only: upgrading should not cost someone
     * their founding rate.
     *
     * A plan with no grandfathered price configured falls back to its standard
     * one, so half-configured Stripe accounts overcharge nobody and simply do
     * not grandfather.
     */
    private function priceIdFor(int $tenantId, string $plan): string
    {
        $standard = trim((string) Env::get(
            $plan === PlanService::PLAN_STUDIO
                ? 'STRIPE_PRICE_STUDIO_MONTHLY'
                : 'STRIPE_PRICE_SOLO_MONTHLY',
            ''
        ));

        $status = $this->plans->status($tenantId);
        $availability = $this->plans->foundingAvailability();

        if (!$status['is_founding'] && $availability['remaining'] < 1) {
            return $standard;
        }

        $envKey = PlanService::foundingPriceEnvKey($plan);

        if ($envKey === null) {
            return $standard;
        }

        $founding = trim((string) Env::get($envKey, ''));

        return $founding !== '' ? $founding : $standard;
    }

    private function currentUser(): array
    {
        $user = User::find((int) Auth::id());

        if (!$user) {
            throw new \RuntimeException('Authenticated user could not be loaded.');
        }

        return $user;
    }
}
