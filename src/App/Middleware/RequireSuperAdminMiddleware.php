<?php

namespace Keel\App\Middleware;

use Keel\App\Support\Operator;
use Keel\Core\Middleware;
use Keel\Core\Request;
use Keel\Core\Response;

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
        // The same call the navigation uses to decide whether to show a link
        // here. One answer in one place: a visible link this door refuses is a
        // bug report, and the two drifting apart is how that happens.
        //
        // Operator::isCurrent() re-reads is_super_admin from the database and
        // returns false inside an impersonated session; both of those matter
        // and neither is the session's word for it.
        if (!Operator::isCurrent()) {
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
