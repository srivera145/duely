<?php

namespace Keel\App\Controllers\SuperAdmin;

use Keel\App\Models\Chase;
use Keel\App\Services\Clock;
use Keel\App\Services\OrganizationService;
use Keel\App\Services\PlanService;
use Keel\Core\Database;
use Keel\Core\Request;

/**
 * Tier three: administering an account.
 *
 * Everything here writes to the audit trail, and the destructive actions require
 * the organization's name typed out rather than a confirm dialog. The mistake
 * worth preventing is not "meant to click cancel" — it is acting on the wrong
 * tenant, and only re-reading the name catches that.
 */
class AccountsController extends BaseController
{
    public function index(Request $request): void
    {
        $this->panel('super-admin.organizations', 'accounts.list', [
            'title' => 'Accounts — Duely',
            'organizations' => (new OrganizationService())->allOrganizations(),
            'selectedOrganization' => null,
            'selectedMembers' => [],
            'plans' => [PlanService::PLAN_FREE, PlanService::PLAN_SOLO, PlanService::PLAN_STUDIO],
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $tenantId = (int) $organization['id'];

        $this->panel('super-admin.organizations', 'accounts.view', [
            'title' => 'Accounts — Duely',
            'organizations' => (new OrganizationService())->allOrganizations(),
            'selectedOrganization' => $organization,
            'selectedMembers' => (new OrganizationService())->members($tenantId),
            'plans' => [PlanService::PLAN_FREE, PlanService::PLAN_SOLO, PlanService::PLAN_STUDIO],
            'planStatus' => (new PlanService())->status($tenantId),
        ], $tenantId);
    }

    /**
     * POST /super-admin/organizations/{id}/trial — extend a trial.
     */
    public function extendTrial(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $days = max(1, min((int) $request->input('days', 14), 365));
        $tenantId = (int) $organization['id'];

        // Extended from now rather than from the old end date, so extending an
        // expired trial gives the full period instead of silently landing in
        // the past.
        $until = Clock::now()->modify('+' . $days . ' days');

        Database::connection()
            ->prepare('UPDATE organizations SET trial_ends_at = ? WHERE id = ?')
            ->execute([Clock::toDatabase($until), $tenantId]);

        $this->audit->recordAction('account.trial_extended', $tenantId, [
            'days' => $days,
            'until' => Clock::toDatabase($until),
        ], (string) $request->input('reason', ''));

        $this->back($tenantId, 'Trial extended by ' . $days . ' days.');
    }

    /**
     * POST /super-admin/organizations/{id}/founding — grant or revoke a slot.
     */
    public function foundingSlot(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $tenantId = (int) $organization['id'];
        $grant = (string) $request->input('grant', 'yes') === 'yes';

        if ($grant) {
            // Through the existing atomic claim, never a direct UPDATE. The
            // claim is a single conditional UPDATE against the free rows: bypass
            // it and two grants can hand out the same slot, or a grant can
            // invent slot 51 after all fifty are taken.
            $result = (new PlanService())->claimFoundingSlot($tenantId);

            $this->audit->recordAction('account.founding_granted', $tenantId, [
                'claimed' => $result['claimed'],
                'slot' => $result['slot'],
            ], (string) $request->input('reason', ''));

            $this->back(
                $tenantId,
                $result['claimed']
                    ? 'Founding slot ' . $result['slot'] . ' granted.'
                    : (string) $result['reason']
            );
        }

        // Revoking releases the slot back to the pool rather than deleting the
        // row, which is what keeps the table exactly fifty rows long.
        Database::transaction(function () use ($tenantId): void {
            Database::connection()
                ->prepare('UPDATE founding_slots SET tenant_id = NULL, claimed_at = NULL WHERE tenant_id = ?')
                ->execute([$tenantId]);

            Database::connection()
                ->prepare('UPDATE organizations SET is_founding = 0, founding_slot = NULL WHERE id = ?')
                ->execute([$tenantId]);
        });

        $this->audit->recordAction(
            'account.founding_revoked',
            $tenantId,
            [],
            (string) $request->input('reason', '')
        );

        $this->back($tenantId, 'Founding slot released.');
    }

    /**
     * POST /super-admin/organizations/{id}/plan — change plan without Stripe.
     */
    public function changePlan(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $plan = (string) $request->input('plan', '');
        $allowed = [PlanService::PLAN_FREE, PlanService::PLAN_SOLO, PlanService::PLAN_STUDIO];

        if (!in_array($plan, $allowed, true)) {
            $this->back((int) $organization['id'], 'That is not a plan.');
        }

        $tenantId = (int) $organization['id'];
        $before = (string) $organization['plan'];

        Database::connection()
            ->prepare('UPDATE organizations SET plan = ? WHERE id = ?')
            ->execute([$plan, $tenantId]);

        // Recorded with the previous value: an audit entry saying what it
        // became, without what it was, cannot be reversed by whoever reads it.
        $this->audit->recordAction('account.plan_changed', $tenantId, [
            'from' => $before,
            'to' => $plan,
            'note' => 'Set directly; no Stripe subscription was created or changed.',
        ], (string) $request->input('reason', ''));

        $this->back($tenantId, 'Plan set to ' . $plan . '. Stripe was not touched.');
    }

    /**
     * POST /super-admin/organizations/{id}/disable
     */
    public function disable(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $tenantId = (int) $organization['id'];

        if (!$this->confirmedByName($request, $organization)) {
            $this->back($tenantId, 'Type the organization name exactly to confirm.');
        }

        $reason = trim((string) $request->input('reason', ''));

        Database::connection()
            ->prepare('UPDATE organizations SET disabled_at = ?, disabled_reason = ? WHERE id = ?')
            ->execute([Clock::toDatabase(Clock::now()), mb_substr($reason, 0, 255) ?: null, $tenantId]);

        // Disabling an account stops its reminders. Leaving them running would
        // mean a suspended workspace still emailing its clients.
        $paused = Chase::pauseAllForTenant($tenantId, Chase::PAUSE_MANUAL);

        $this->audit->recordAction('account.disabled', $tenantId, ['chases_paused' => $paused], $reason);

        $this->back($tenantId, 'Account disabled and ' . $paused . ' chases paused.');
    }

    /**
     * POST /super-admin/organizations/{id}/enable
     */
    public function enable(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $tenantId = (int) $organization['id'];

        Database::connection()
            ->prepare('UPDATE organizations SET disabled_at = NULL, disabled_reason = NULL WHERE id = ?')
            ->execute([$tenantId]);

        // Chases stay paused. Re-enabling restores access; resuming somebody's
        // reminders is their decision, not the operator's, and getting it wrong
        // means a client hears from them unexpectedly.
        $this->audit->recordAction(
            'account.enabled',
            $tenantId,
            ['note' => 'Chases left paused for the owner to resume.'],
            (string) $request->input('reason', '')
        );

        $this->back($tenantId, 'Account re-enabled. Reminders are still paused for the owner to resume.');
    }

    /**
     * POST /super-admin/organizations/{id}/pause-chases
     */
    public function pauseChases(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $tenantId = (int) $organization['id'];

        if (!$this->confirmedByName($request, $organization)) {
            $this->back($tenantId, 'Type the organization name exactly to confirm.');
        }

        $paused = Chase::pauseAllForTenant($tenantId, Chase::PAUSE_MANUAL);

        $this->audit->recordAction(
            'account.chases_paused',
            $tenantId,
            ['count' => $paused],
            (string) $request->input('reason', '')
        );

        $this->back($tenantId, $paused . ' chases paused.');
    }

    /**
     * POST /super-admin/organizations/{id}/reset-sessions
     */
    public function resetSessions(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $tenantId = (int) $organization['id'];

        if (!$this->confirmedByName($request, $organization)) {
            $this->back($tenantId, 'Type the organization name exactly to confirm.');
        }

        // One timestamp per user rather than a token sweep: every session issued
        // before this instant fails its next request, wherever it is running.
        $statement = Database::connection()->prepare(
            'UPDATE users SET sessions_invalidated_at = ? WHERE organization_id = ?'
        );
        $statement->execute([Clock::toDatabase(Clock::now()), $tenantId]);

        $this->audit->recordAction(
            'account.sessions_reset',
            $tenantId,
            ['users' => $statement->rowCount()],
            (string) $request->input('reason', '')
        );

        $this->back($tenantId, 'Signed out ' . $statement->rowCount() . ' users.');
    }

    /**
     * POST /super-admin/organizations/{id}/resend-invite
     */
    public function resendInvite(Request $request, string $id): never
    {
        $organization = $this->organization((int) $id);

        if ($organization === null) {
            $this->redirect('/super-admin/organizations');
        }

        $tenantId = (int) $organization['id'];
        $inviteId = (int) $request->input('invite_id', 0);

        $result = (new OrganizationService())->resendInvite($tenantId, $inviteId);

        $this->audit->recordAction('account.invite_resent', $tenantId, [
            'invite_id' => $inviteId,
            'sent' => $result['sent'],
        ], (string) $request->input('reason', ''));

        $this->back($tenantId, $result['message']);
    }

    // -------------------------------------------------------------- internals

    private function organization(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    private function back(int $tenantId, string $message): never
    {
        $this->redirect('/super-admin/organizations/' . $tenantId . '?notice=' . rawurlencode($message));
    }
}
