<?php

namespace Keel\App\Controllers;

use Keel\App\Services\TemplateRenderer;
use Keel\App\Services\TenantContext;
use Keel\App\Services\ToneAssistService;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * The writing assistant endpoints.
 *
 * Both return a *proposal*. Neither writes to a sequence — the user accepts a
 * draft in the editor and saves it through the ordinary sequence save, which
 * already validates merge tags and offsets. That keeps one write path rather
 * than a second one that bypasses the first's checks.
 */
class ToneAssistController extends Controller
{
    public function __construct(private readonly ToneAssistService $assist = new ToneAssistService())
    {
    }

    /**
     * POST /api/tone-assist/rewrite
     */
    public function rewrite(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->requirePlan($tenantId);

        $subject = (string) $request->input('subject_template', '');
        $body = (string) $request->input('body_template', '');

        if (trim($subject) === '' && trim($body) === '') {
            $this->json(['error' => 'There is nothing to rewrite yet.'], 422);
        }

        $result = $this->assist->rewriteStep(
            $tenantId,
            $subject,
            $body,
            (string) $request->input('tone', 'polite'),
            (string) $request->input('instruction', '')
        );

        $this->respond($tenantId, ToneAssistService::ACTION_REWRITE, $result, [
            // The original, so the editor can show a side-by-side diff rather
            // than silently replacing what the user wrote.
            'original' => ['subject' => $subject, 'body' => $body],
        ]);
    }

    /**
     * POST /api/tone-assist/sequence
     */
    public function sequence(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->requirePlan($tenantId);

        $result = $this->assist->generateSequence(
            $tenantId,
            (string) $request->input('description', '')
        );

        $this->respond($tenantId, ToneAssistService::ACTION_SEQUENCE, $result);
    }

    /**
     * GET /api/tone-assist/allowance
     */
    public function allowance(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->json([
            'configured' => ToneAssistService::isConfigured(),
            'allowance' => $this->assist->allowance($tenantId),
            'usage' => $this->assist->usageSummary($tenantId),
            'tags' => TemplateRenderer::tags(),
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * @param array{ok:bool, proposal:?array, error:?string, allowance:array, redactions:array} $result
     */
    /**
     * The plan gate, applied before anything is spent.
     */
    private function requirePlan(int $tenantId): void
    {
        $allowance = (new \Keel\App\Services\PlanService())
            ->canUseFeature($tenantId, \Keel\App\Services\PlanService::FEATURE_TONE_ASSIST);

        if (!$allowance['allowed']) {
            $this->json([
                'error' => $allowance['reason'],
                'upgrade_to' => $allowance['upgrade_to'],
            ], 402);
        }
    }

    private function respond(int $tenantId, string $action, array $result, array $extra = []): void
    {
        if (!$result['ok']) {
            // A refusal is a normal outcome, not a server fault: the user gets
            // a sentence and their template is untouched.
            $this->json([
                'error' => $result['error'],
                'allowance' => $result['allowance'],
            ], 422);
        }

        Activity::log('tone_assist.' . $action, 'Sequence', null, [
            'redactions' => $result['redactions'],
        ]);

        $this->json(array_merge([
            'proposal' => $result['proposal'],
            'allowance' => $result['allowance'],
            // Shown to the user so they can see what Duely removed before
            // sending, rather than having to take it on trust.
            'redactions' => $result['redactions'],
            // Nothing has been written. The editor holds the draft until the
            // user accepts it and saves the sequence.
            'saved' => false,
        ], $extra));
    }
}
