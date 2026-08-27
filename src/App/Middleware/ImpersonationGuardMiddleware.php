<?php

namespace Keel\App\Middleware;

use Keel\App\Services\ImpersonationService;
use Keel\Core\Middleware;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * What an impersonated session is not allowed to do.
 *
 * The list below is the promise the privacy page makes, in code. It is enforced
 * here, on the request, keyed off the session — not by templates declining to
 * render buttons. A hidden button is not a control; it is a suggestion, and
 * anybody with curl can decline it.
 *
 * The rule is deliberately a denylist of *paths* rather than a flag each
 * controller remembers to check. A new endpoint that sends email is a new
 * endpoint somebody has to remember to guard, and the whole point of putting it
 * here is that forgetting is not fatal: the safe default below is that anything
 * matching a blocked prefix is refused whether or not its author thought about
 * impersonation.
 *
 * Reads are untouched. Seeing what the customer sees is the entire purpose.
 */
class ImpersonationGuardMiddleware implements Middleware
{
    /**
     * Path prefixes an impersonated session cannot reach with a writing method.
     *
     * Each entry carries why, because a list like this rots into superstition
     * the moment nobody remembers what it was protecting.
     */
    private const BLOCKED = [
        // Anything that reaches a client's inbox. The single most important
        // one: support must never send mail as a customer.
        '/api/email-account/send-test' => 'send email',
        '/api/email-account' => 'change mailbox settings',
        '/settings/email' => 'change mailbox settings',

        // Anything that starts or advances a chase, for the same reason.
        '/api/chases' => 'start or change reminders',
        '/api/invoices/import' => 'import invoices',

        // Money.
        '/billing' => 'change billing',
        '/api/billing' => 'change billing',
        '/settings/payments' => 'change payment settings',

        // Identity and destruction.
        '/settings/members' => 'invite or remove people',
        '/settings/organization' => 'change the organization',
        '/settings/api-tokens' => 'issue API tokens',
        '/api/organization' => 'change the organization',
    ];

    /**
     * Blocked outright, method regardless: these leak or act by being loaded.
     */
    private const BLOCKED_ALWAYS = [
        '/super-admin' => 'reach the operator panel',
    ];

    public function handle(Request $request, \Closure $next): mixed
    {
        if (!(new ImpersonationService())->isActive()) {
            return $next($request);
        }

        $path = rtrim($request->uri, '/') ?: '/';

        foreach (self::BLOCKED_ALWAYS as $prefix => $what) {
            if ($this->matches($path, $prefix)) {
                $this->refuse($request, $what);
            }
        }

        // GET is reading, and reading is the point. Everything that changes
        // state in this application is POST.
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return $next($request);
        }

        foreach (self::BLOCKED as $prefix => $what) {
            if ($this->matches($path, $prefix)) {
                $this->refuse($request, $what);
            }
        }

        // Deleting anything, wherever it lives.
        if (str_contains($path, '/delete') || str_contains($path, '/destroy')) {
            $this->refuse($request, 'delete anything');
        }

        return $next($request);
    }

    private function matches(string $path, string $prefix): bool
    {
        // The separator matters: without it `/billing` would also match
        // `/billing-history`, and a prefix list that over-matches is one
        // somebody will quietly delete the first time it blocks something real.
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    private function refuse(Request $request, string $what): never
    {
        $message = 'A support session cannot ' . $what . '.';

        if ($request->wantsJson()) {
            Response::json(['error' => $message], 403);
        }

        Response::abort(403, $message);
    }
}
