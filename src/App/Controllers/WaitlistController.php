<?php

namespace Keel\App\Controllers;

use Keel\App\Services\WaitlistService;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * Waitlist capture and confirmation.
 *
 * The join endpoint answers identically whatever it finds, so it cannot be used
 * to check whether an address is on the list. The confirmation page is a real
 * page rather than a JSON response, because the link is clicked from an email
 * client and lands in a browser with no JavaScript context of its own.
 */
class WaitlistController extends Controller
{
    public function __construct(private readonly WaitlistService $waitlist = new WaitlistService())
    {
    }

    /**
     * POST /api/waitlist
     */
    public function join(Request $request): void
    {
        $email = (string) $request->input('email', '');

        if (trim($email) === '') {
            $this->respond($request, 'invalid', 'Enter your email address.', 422);
        }

        // A bot fills in every field it finds. A human never sees this one.
        if (trim((string) $request->input('company', '')) !== '') {
            // Answer as though it worked. Telling a bot it was caught only
            // teaches whoever wrote it to leave the field alone next time.
            $this->respond($request, 'sent', 'Check your inbox — we have sent you a link to confirm.');
        }

        // Attribution comes from the form, which the page fills in from its own
        // URL. Reading it here rather than trusting a client-supplied "source"
        // free-text keeps the column to values we chose.
        $context = WaitlistService::contextFrom(
            $this->attribution($request),
            $_SERVER,
            $this->source($request)
        );

        $result = $this->waitlist->join($email, $context);

        if (!$result['ok']) {
            $this->respond(
                $request,
                $result['state'] === 'invalid' ? 'invalid' : 'error',
                $result['message'],
                $result['state'] === 'invalid' ? 422 : 503
            );
        }

        // The address is deliberately not logged: the audit trail is for
        // account actions, and a waitlist signup is not one yet.
        Activity::log('waitlist.joined', 'Waitlist', null, [
            'source' => $context['source'],
            'utm_source' => $context['utm_source'],
            'utm_campaign' => $context['utm_campaign'],
        ]);

        $this->respond($request, 'sent', $result['message']);
    }

    /**
     * GET /waitlist/confirm
     */
    public function confirm(Request $request): void
    {
        $result = $this->waitlist->confirm((string) $request->input('token', ''));

        $this->view('marketing.confirmed', [
            'title' => $result['ok']
                ? 'You are on the list - Duely'
                : 'That link has expired - Duely',
            'metaDescription' => 'Confirm your place on the Duely waitlist.',
            'noindex' => true,
            'confirmed' => $result['ok'],
            'message' => $result['message'],
            'state' => $result['state'],
        ]);
    }

    /**
     * GET /waitlist/unsubscribe
     */
    public function unsubscribe(Request $request): void
    {
        $result = $this->waitlist->unsubscribe(
            (string) $request->input('email', ''),
            (string) $request->input('token', '')
        );

        $this->view('marketing.confirmed', [
            'title' => $result['ok'] ? 'Removed - Duely' : 'That link is not valid - Duely',
            'metaDescription' => 'Manage your place on the Duely waitlist.',
            'noindex' => true,
            'confirmed' => $result['ok'],
            'message' => $result['message'],
            'state' => $result['state'],
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * Answer in whichever form the caller can read.
     *
     * The page uses fetch, but the form is a real form and posts on its own if
     * the script never arrives — a landing page whose only conversion path
     * depends on JavaScript loading is a landing page that sometimes has none.
     */
    private function respond(Request $request, string $state, string $message, int $status = 200): never
    {
        if ($request->wantsJson()) {
            $this->json($state === 'sent' ? ['message' => $message] : ['error' => $message], $status);
        }

        // Only the state travels in the URL. The sentence is chosen by the
        // page, so a crafted link cannot make this site say anything it likes.
        $this->redirect($this->returnPath($request) . '?waitlist=' . $state . '#waitlist');
    }

    /**
     * Where a no-JavaScript submission goes back to.
     *
     * Only the public pages this site actually has. Redirecting to whatever
     * path was posted would be an open redirect with a form in front of it.
     */
    private function returnPath(Request $request): string
    {
        $path = (string) $request->input('landing_path', '/');
        $allowed = ['/', '/how-it-works', '/pricing'];

        return in_array($path, $allowed, true) ? $path : '/';
    }

    /**
     * The UTM values the form carried, taken one known key at a time.
     */
    private function attribution(Request $request): array
    {
        $attribution = [
            'landing_path' => (string) $request->input('landing_path', ''),
        ];

        foreach (WaitlistService::UTM_KEYS as $key) {
            $attribution[$key] = (string) $request->input($key, '');
        }

        return $attribution;
    }

    /**
     * Which form this was, from a fixed list. Anything else is just "landing".
     */
    private function source(Request $request): string
    {
        $source = strtolower(trim((string) $request->input('source', '')));

        $known = ['landing_hero', 'landing_footer', 'how_it_works', 'pricing'];

        return in_array($source, $known, true) ? $source : 'landing';
    }
}
