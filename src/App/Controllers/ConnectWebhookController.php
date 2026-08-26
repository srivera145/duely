<?php

namespace Keel\App\Controllers;

use Keel\App\Services\ConnectWebhookHandler;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * The Connect webhook.
 *
 * A **separate endpoint with its own signing secret**, deliberately. The
 * subscription webhook at `/webhooks/stripe` says who is paying Duely; this one
 * says who is paying Duely's users. Sharing a secret between them would mean an
 * event captured from either endpoint could be replayed against the other.
 *
 * Note on placement: the brief called for `public_html/webhooks/stripe-connect.php`.
 * It is a route instead, because a PHP file under the document root is served
 * directly by Apache and never passes through the router — it would have no
 * bootstrap, no environment, and no middleware. Same URL, same behaviour, but
 * the request actually reaches the application. `/webhooks/stripe-connect` is
 * registered outside every middleware group: Stripe has no session and no CSRF
 * token, and its signature is the authentication.
 */
class ConnectWebhookController extends Controller
{
    public function __construct(private readonly ConnectWebhookHandler $handler = new ConnectWebhookHandler())
    {
    }

    public function handle(Request $request): never
    {
        $payload = $request->rawBody();
        $signature = $request->headers['Stripe-Signature']
            ?? $request->headers['stripe-signature']
            ?? $_SERVER['HTTP_STRIPE_SIGNATURE']
            ?? '';
        $secret = trim((string) Env::get('STRIPE_CONNECT_WEBHOOK_SECRET', ''));

        if ($payload === '' || $signature === '' || $secret === '') {
            Response::json(['error' => 'Invalid webhook payload.'], 400);
        }

        try {
            // Throws on a bad signature, a stale timestamp, or a tampered body.
            // Nothing below this line trusts the payload until it has passed.
            Webhook::constructEvent($payload, (string) $signature, $secret);
        } catch (UnexpectedValueException | SignatureVerificationException $exception) {
            error_log('[Duely] Connect webhook signature rejected: ' . $exception->getMessage());
            Response::json(['error' => 'Webhook signature verification failed.'], 400);
        }

        try {
            $result = $this->handler->handle(json_decode($payload, true) ?? [], $payload);
        } catch (Throwable $exception) {
            // 500 so Stripe retries. The event is marked failed in
            // connect_events, which is what makes the retry reclaimable.
            error_log('[Duely] Connect webhook processing failed: ' . $exception->getMessage());
            Response::json(['error' => 'Webhook processing failed.'], 500);
        }

        // A duplicate is a success from Stripe's point of view: the work is
        // done, and retrying would achieve nothing.
        Response::json([
            'received' => true,
            'handled' => $result['handled'],
            'duplicate' => $result['duplicate'],
        ]);
    }
}
