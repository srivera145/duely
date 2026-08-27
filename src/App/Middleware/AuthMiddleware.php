<?php

namespace Keel\App\Middleware;

use Keel\App\Models\User;
use Keel\App\Services\ImpersonationService;
use Keel\Core\Auth;
use Keel\Core\Middleware;
use Keel\Core\Request;
use Keel\Core\Response;
use Keel\Core\Session;
use Keel\Core\Theme;

class AuthMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!Session::has('user_id')) {
            if ($request->wantsJson()) {
                Response::json(['error' => 'Unauthenticated.'], 401);
            }
            Response::redirect('/login');
        }

        $user = User::find((int) Session::get('user_id'));

        if ($user === null) {
            Session::destroy();

            if ($request->wantsJson()) {
                Response::json(['error' => 'Unauthenticated.'], 401);
            }

            Response::redirect('/login');
        }

        // A forced session reset invalidates every session issued before the
        // moment it was pressed. One timestamp logs the user out everywhere,
        // including on machines nobody can physically reach.
        if ($this->sessionPredatesReset($user)) {
            Session::destroy();

            if ($request->wantsJson()) {
                Response::json(['error' => 'Your session has ended.'], 401);
            }

            Response::redirect('/login?notice=session_reset');
        }

        // Impersonation is resolved once, here, and everything downstream reads
        // Auth::id() as it always has. Doing it at the single chokepoint means
        // there is no second place to forget: TenantContext, the models and the
        // activity log all follow automatically.
        //
        // Session::get('user_id') still holds the operator, which is how the
        // audit trail names the right human.
        $session = (new ImpersonationService())->current();

        Auth::setUserId($session === null ? null : (int) $session['target_user_id']);

        Session::put('theme_preference', Theme::normalize($user['theme_preference'] ?? null));

        return $next($request);
    }

    /**
     * Was this session issued before the account's last forced reset?
     */
    private function sessionPredatesReset(array $user): bool
    {
        $resetAt = $user['sessions_invalidated_at'] ?? null;

        if ($resetAt === null) {
            return false;
        }

        $issuedAt = Session::get('issued_at');

        // A session with no stamp predates the feature, so it is treated as
        // older than any reset. Failing closed is the only safe direction: the
        // alternative leaves alive exactly the sessions a reset was meant to kill.
        if ($issuedAt === null) {
            return true;
        }

        return (string) $issuedAt < (string) $resetAt;
    }
}
