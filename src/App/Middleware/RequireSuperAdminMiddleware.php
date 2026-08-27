<?php

namespace Keel\App\Middleware;

use Keel\App\Services\ImpersonationService;
use Keel\Core\Database;
use Keel\Core\Middleware;
use Keel\Core\Request;
use Keel\Core\Response;
use Keel\Core\Session;

/**
 * The door to the operator panel.
 *
 * Three things it does that are worth stating, because each one is a decision
 * rather than an accident.
 *
 * **404, never 403.** A 403 confirms the path exists, which is a free map of the
 * panel for anybody probing. To a non-operator, `/super-admin/anything` is
 * indistinguishable from a typo.
 *
 * **`is_super_admin` is re-read from the database every request.** Not from the
 * session. Revoking somebody's operator rights has to take effect on their next
 * request, not at their next login — otherwise the revocation is a request to
 * please log out, addressed to the person you just decided not to trust.
 *
 * **Impersonation cannot reach it.** An operator inside a customer's session is
 * blocked here as firmly as a stranger. Escalating back out would let the
 * customer's session be a springboard into the panel, and would make every audit
 * entry from that point name the wrong actor.
 */
class RequireSuperAdminMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): mixed
    {
        // Checked first. An impersonated session belongs to the customer for the
        // duration, whatever the operator behind it is entitled to elsewhere.
        if ((new ImpersonationService())->isActive()) {
            $this->refuse($request);
        }

        // The real operator, not the impersonated identity -- though the check
        // above means they are the same here. Read straight from the session
        // rather than through Auth::id(), which impersonation rewrites.
        $userId = (int) Session::get('user_id', 0);

        if ($userId <= 0) {
            $this->refuse($request);
        }

        $statement = Database::connection()->prepare(
            'SELECT is_super_admin FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();

        if (!$row || (int) $row['is_super_admin'] !== 1) {
            $this->refuse($request);
        }

        return $next($request);
    }

    /**
     * Indistinguishable from a page that does not exist.
     */
    private function refuse(Request $request): never
    {
        if ($request->wantsJson()) {
            Response::json(['error' => 'Not found.'], 404);
        }

        Response::abort(404, 'Not found');
    }
}
