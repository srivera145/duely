<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Sequence;
use Keel\App\Services\OnboardingService;
use Keel\App\Services\PlanService;
use Keel\App\Services\TenantContext;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * The guided first run.
 *
 * The wizard never blocks anything: every step links to the real screen that
 * does the work, and skipping leaves everything reachable. It exists to answer
 * "what now?", not to gate the product.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarding = new OnboardingService(),
        private readonly PlanService $plans = new PlanService(),
    ) {
    }

    /**
     * GET /onboarding
     */
    public function index(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $this->onboarding->sync($tenantId);

        $progress = $this->onboarding->progress($tenantId);

        $this->view('onboarding.index', [
            'title' => 'Getting started - Duely',
            'metaDescription' => 'Four steps to your first automatic reminder.',
            'progress' => $progress,
            'status' => $this->plans->status($tenantId),
            'founding' => $this->plans->foundingAvailability(),
            'sequence' => Sequence::defaultSequence($tenantId),
        ]);
    }

    /**
     * POST /api/onboarding/reviewed — the one step with nothing to detect.
     */
    public function markReviewed(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $this->onboarding->markReviewed($tenantId);

        $this->json(['progress' => $this->onboarding->progress($tenantId)]);
    }

    /**
     * POST /api/onboarding/skip
     */
    public function skip(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $this->onboarding->markSkipped($tenantId);

        Activity::log('onboarding.skipped', 'Organization', $tenantId);

        $this->json(['progress' => $this->onboarding->progress($tenantId)]);
    }

    /**
     * POST /api/onboarding/resume — come back to a wizard skipped earlier.
     */
    public function resume(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $this->onboarding->resume($tenantId);

        $this->json(['progress' => $this->onboarding->progress($tenantId)]);
    }

    /**
     * GET /api/onboarding/progress
     */
    public function progress(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $this->onboarding->sync($tenantId);

        $this->json(['progress' => $this->onboarding->progress($tenantId)]);
    }
}
