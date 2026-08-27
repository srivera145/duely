<?php

namespace Keel\App\Controllers\SuperAdmin;

use Keel\App\Services\ImpersonationService;
use Keel\App\Services\OtpService;
use Keel\App\Services\SupportAccessLog;
use Keel\Core\Database;
use Keel\Core\Request;
use Keel\Core\Session;

/**
 * Signing in as a customer.
 *
 * The most dangerous thing in the application, so the gate is three-part and
 * every part has a reason:
 *
 *   A stated reason, at least ten characters, stored and shown to the customer.
 *   A fresh OTP, even with a valid session — a stolen laptop should not be a
 *   tenant-data breach, and a session cookie is exactly what a stolen laptop
 *   has.
 *   A thirty-minute expiry with no renewal.
 *
 * The re-authentication is the part most likely to be argued away as friction.
 * It is not friction; it is the difference between "somebody took the operator's
 * machine" and "somebody read every customer's invoices".
 */
class ImpersonationController extends BaseController
{
    public function __construct(
        private readonly ImpersonationService $impersonation = new ImpersonationService(),
        private readonly OtpService $otp = new OtpService(),
        ?SupportAccessLog $audit = null,
    ) {
        parent::__construct($audit);
    }

    /**
     * GET /super-admin/impersonate/{userId} — the gate.
     */
    public function confirm(Request $request, string $userId): void
    {
        $target = $this->target((int) $userId);

        if ($target === null) {
            $this->redirect('/super-admin/support');
        }

        $this->panel('super-admin.impersonate', 'impersonation.gate', [
            'title' => 'Sign in as — Duely',
            'target' => $target,
            'maxMinutes' => ImpersonationService::MAX_MINUTES,
            'minReason' => SupportAccessLog::MIN_REASON_LENGTH,
            'codeSent' => (bool) Session::get('impersonation_code_sent', false),
            'error' => $request->query['error'] ?? null,
        ], $target['organization_id'] === null ? null : (int) $target['organization_id']);
    }

    /**
     * POST /super-admin/impersonate/{userId}/code — send the operator an OTP.
     */
    public function sendCode(Request $request, string $userId): never
    {
        $target = $this->target((int) $userId);

        if ($target === null) {
            $this->redirect('/super-admin/support');
        }

        $admin = $this->admin();

        if ($admin === null) {
            $this->redirect('/super-admin/support');
        }

        // To the operator's own address, never the target's. Sending the code
        // to the account being impersonated would be handing the key to the
        // door it opens.
        $this->otp->requestCode((string) $admin['email']);
        Session::put('impersonation_code_sent', true);

        $this->redirect('/super-admin/impersonate/' . (int) $target['id']);
    }

    /**
     * POST /super-admin/impersonate/{userId} — reason plus code, then in.
     */
    public function start(Request $request, string $userId): never
    {
        $target = $this->target((int) $userId);

        if ($target === null) {
            $this->redirect('/super-admin/support');
        }

        $targetId = (int) $target['id'];
        $admin = $this->admin();

        if ($admin === null) {
            $this->redirect('/super-admin/support');
        }

        $reason = trim((string) $request->input('reason', ''));
        $code = trim((string) $request->input('code', ''));

        if (!SupportAccessLog::isUsableReason($reason)) {
            $this->fail($targetId, 'Give a reason of at least '
                . SupportAccessLog::MIN_REASON_LENGTH . ' characters.');
        }

        // Re-authentication. The session already proves the operator signed in
        // at some point; this proves they are here now.
        $verified = $this->otp->verifyCode((string) $admin['email'], $code);

        if (!($verified['success'] ?? false)) {
            // Logged as a refusal, not silently retried. A run of these is
            // somebody trying codes.
            $this->audit->recordAction('impersonation.refused', null, [
                'target_user_id' => $targetId,
                'why' => 'code',
            ], $reason);

            $this->fail($targetId, 'That code is not valid. Request a new one.');
        }

        Session::forget('impersonation_code_sent');

        $result = $this->impersonation->start((int) $admin['id'], $targetId, $reason);

        if (!$result['ok']) {
            $this->fail($targetId, (string) $result['error']);
        }

        // Into the customer's dashboard, which is what the operator came to
        // look at. The banner rides along on every page from here.
        $this->redirect('/dashboard');
    }

    /**
     * POST /impersonation/stop — the banner's exit.
     *
     * Outside the panel on purpose: an impersonated session is blocked from
     * every `/super-admin` route, so the way out cannot live there.
     */
    public function stop(Request $request): never
    {
        $this->impersonation->stop();

        $this->redirect('/super-admin/support');
    }

    // -------------------------------------------------------------- internals

    private function fail(int $targetId, string $message): never
    {
        $this->redirect('/super-admin/impersonate/' . $targetId . '?error=' . rawurlencode($message));
    }

    private function target(int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT u.id, u.name, u.email, u.organization_id, u.is_super_admin, o.name AS organization_name
             FROM users u
             LEFT JOIN organizations o ON o.id = u.organization_id
             WHERE u.id = ? LIMIT 1'
        );
        $statement->execute([$userId]);

        return $statement->fetch() ?: null;
    }

    /**
     * The operator. Read from the session rather than Auth::id(), which
     * impersonation rewrites -- though the middleware means this is never
     * reached from inside a session anyway.
     */
    private function admin(): ?array
    {
        $userId = ImpersonationService::realUserId();

        if ($userId === null) {
            return null;
        }

        $statement = Database::connection()->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$userId]);

        return $statement->fetch() ?: null;
    }
}
