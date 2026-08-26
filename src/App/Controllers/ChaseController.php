<?php

namespace Keel\App\Controllers;

use Keel\App\Mail\SmtpTransport;
use Keel\App\Models\Chase;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Services\ChaseScheduler;
use Keel\App\Services\ChaseSender;
use Keel\App\Services\Clock;
use Keel\App\Services\TenantContext;
use Keel\App\Services\UndoService;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * Manual controls over a running chase.
 *
 * Every action here is tenant-scoped through the models, logged to Keel's
 * audit trail, and reached only through CSRF-protected POST routes.
 */
class ChaseController extends Controller
{
    public function __construct(
        private readonly ChaseScheduler $scheduler = new ChaseScheduler(),
        private readonly UndoService $undo = new UndoService(),
    ) {
    }

    /**
     * POST /api/chases/{id}/pause
     */
    public function pause(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $chase = $this->requireChase($tenantId, (int) $id);

        if (!in_array($chase['status'], [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE], true)) {
            $this->json(['error' => 'This chase is not running.'], 409);
        }

        Chase::pause($tenantId, (int) $chase['id'], Chase::PAUSE_MANUAL);
        Activity::log('chase.paused', 'Chase', (int) $chase['id'], ['by' => 'user']);

        $this->json(['chase' => $this->present($tenantId, (int) $chase['id'])]);
    }

    /**
     * POST /api/chases/{id}/resume
     */
    public function resume(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $chase = $this->requireChase($tenantId, (int) $id);

        if ($chase['status'] !== Chase::STATUS_PAUSED) {
            $this->json(['error' => 'This chase is not paused.'], 409);
        }

        $invoice = Invoice::withClient($tenantId, (int) $chase['invoice_id']);

        if ($invoice === null || $invoice['status'] !== Invoice::STATUS_OPEN) {
            $this->json(['error' => 'Only an open invoice can be chased.'], 409);
        }

        $sequence = Sequence::find($tenantId, (int) $chase['sequence_id']);

        if ($sequence === null) {
            $this->json(['error' => 'The sequence for this chase no longer exists.'], 409);
        }

        // Pick up from the step after the one already sent, timed from the due
        // date as usual, so resuming does not replay the whole ladder.
        $plan = $this->scheduler->planNext(
            $tenantId,
            $chase,
            $invoice,
            $sequence,
            (int) $chase['current_position'],
            Clock::now()
        );

        Chase::resume($tenantId, (int) $chase['id'], $plan['next_send_at']);
        Activity::log('chase.resumed', 'Chase', (int) $chase['id'], [
            'next_send_at' => Clock::toDatabase($plan['next_send_at']),
        ]);

        $this->json(['chase' => $this->present($tenantId, (int) $chase['id'])]);
    }

    /**
     * POST /api/chases/{id}/stop
     */
    public function stop(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $chase = $this->requireChase($tenantId, (int) $id);

        if (in_array($chase['status'], [Chase::STATUS_STOPPED, Chase::STATUS_COMPLETED], true)) {
            $this->json(['error' => 'This chase has already finished.'], 409);
        }

        Chase::stop($tenantId, (int) $chase['id']);
        Activity::log('chase.stopped', 'Chase', (int) $chase['id'], ['by' => 'user']);

        $this->json(['chase' => $this->present($tenantId, (int) $chase['id'])]);
    }

    /**
     * POST /api/chases/{id}/send-now
     *
     * Fires the next step immediately, ignoring the send window — but not the
     * hard stops. A user asking to send now still must not email a client who
     * has already paid, replied, or bounced.
     */
    public function sendNow(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $chase = $this->requireChase($tenantId, (int) $id);

        if (!in_array($chase['status'], [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE], true)) {
            $this->json([
                'error' => 'This chase is ' . $chase['status'] . '. Resume it before sending.',
            ], 409);
        }

        // Bring the schedule forward. The sender re-checks every hard stop
        // under its own row lock, so nothing here can bypass them.
        Chase::update($tenantId, (int) $chase['id'], [
            'next_send_at' => Clock::toDatabase(Clock::now()->modify('-1 second')),
        ]);

        Activity::log('chase.send_now', 'Chase', (int) $chase['id'], ['by' => 'user']);

        // ignoreWindow only relaxes the time-of-day rule.
        $sender = new ChaseSender(new SmtpTransport());
        $outcome = $sender->processNext($tenantId, Clock::now(), true);

        if ($outcome === null) {
            $this->json(['error' => 'There was nothing ready to send.'], 409);
        }

        $sent = $outcome['outcome'] === 'sent';

        $this->json([
            'sent' => $sent,
            'outcome' => $outcome['outcome'],
            'reason' => $outcome['reason'],
            'chase' => $this->present($tenantId, (int) $chase['id']),
        ], $sent ? 200 : 409);
    }

    /**
     * POST /api/invoices/{id}/mark-paid
     *
     * Stops the chase straight away, and stays reversible for 30 seconds
     * because the commonest mistake is ticking the wrong row.
     */
    public function markPaid(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $invoiceId = (int) $id;

        $invoice = Invoice::find($tenantId, $invoiceId);

        if ($invoice === null) {
            $this->json(['error' => 'That invoice does not exist.'], 404);
        }

        if ($invoice['status'] === Invoice::STATUS_PAID) {
            $this->json(['error' => 'That invoice is already marked paid.'], 409);
        }

        // One shared path with the Stripe webhook -- see InvoicePaymentMarker.
        // Stopping the chase is the point of the button: nothing further goes
        // out to someone who has paid.
        $result = (new \Keel\App\Services\InvoicePaymentMarker())->markPaid(
            $tenantId,
            $invoice,
            \Keel\App\Services\InvoicePaymentMarker::SOURCE_MANUAL
        );

        $undo = $this->undo->remember(
            $tenantId,
            UndoService::ACTION_MARK_PAID,
            'Invoice',
            $invoiceId,
            $result['snapshot']
        );

        $this->json([
            'paid' => true,
            'undo_token' => $undo['token'],
            'undo_expires_in' => $undo['expires_in'],
            'chase' => $result['chase'] === null ? null : $this->present($tenantId, (int) $result['chase']['id']),
        ]);
    }

    /**
     * POST /api/invoices/undo
     */
    public function undoAction(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $token = (string) $request->input('undo_token', '');

        $result = $this->undo->undo($tenantId, $token);

        if (!$result['undone']) {
            $this->json(['error' => $result['reason']], 409);
        }

        Activity::log('invoice.mark_paid_undone', 'Invoice', $result['subject_id']);

        $this->json(['undone' => true, 'invoice_id' => $result['subject_id']]);
    }

    /**
     * POST /api/invoices/{id}/start-chase
     */
    public function startChase(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $invoiceId = (int) $id;

        // The single gate. No controller compares a plan name.
        $allowance = (new \Keel\App\Services\PlanService())
            ->canUseFeature($tenantId, \Keel\App\Services\PlanService::FEATURE_ACTIVE_CHASE);

        if (!$allowance['allowed']) {
            $this->json([
                'error' => $allowance['reason'],
                'limit' => $allowance['limit'],
                'used' => $allowance['used'],
                'upgrade_to' => $allowance['upgrade_to'],
            ], 402);
        }

        $result = $this->scheduler->start(
            $tenantId,
            $invoiceId,
            ($sequenceId = (int) $request->input('sequence_id', 0)) > 0 ? $sequenceId : null,
            null,
            Clock::now()
        );

        if ($result['chase_id'] === 0) {
            $this->json(['error' => $result['reason']], 409);
        }

        if ($result['created']) {
            Activity::log('chase.started', 'Chase', $result['chase_id'], [
                'invoice_id' => $invoiceId,
                'entry_position' => $result['entry_position'],
            ]);
        }

        $this->json([
            'chase' => $this->present($tenantId, $result['chase_id']),
            'created' => $result['created'],
            'reason' => $result['reason'],
        ]);
    }

    // -------------------------------------------------------------- internals

    private function requireChase(int $tenantId, int $chaseId): array
    {
        $chase = Chase::find($tenantId, $chaseId);

        if ($chase === null) {
            // A chase belonging to another tenant is indistinguishable from one
            // that does not exist, which is the point.
            $this->json(['error' => 'That chase does not exist.'], 404);
        }

        return $chase;
    }

    private function present(int $tenantId, int $chaseId): array
    {
        $chase = Chase::find($tenantId, $chaseId);

        if ($chase === null) {
            return [];
        }

        $nextSendAt = Clock::fromDatabase($chase['next_send_at']);

        return [
            'id' => (int) $chase['id'],
            'status' => (string) $chase['status'],
            'paused_reason' => $chase['paused_reason'],
            'current_position' => (int) $chase['current_position'],
            'next_send_at' => $chase['next_send_at'],
            'next_send_relative' => \Keel\App\Services\RelativeTime::phrase($nextSendAt),
        ];
    }
}
