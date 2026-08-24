<?php

namespace Keel\App\Controllers;

use Keel\App\Services\SubscriptionService;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;

/**
 * The Stripe webhook.
 *
 * Three things this endpoint has to get right:
 *
 *   The signature is verified before the payload is read as anything but bytes.
 *   An unsigned request is an attacker granting themselves a subscription.
 *
 *   A replay changes nothing. Stripe retries on any non-2xx and can deliver the
 *   same event twice anyway, so every event is claimed by id first.
 *
 *   A failure returns 5xx so Stripe retries, but a *duplicate* returns 200 so
 *   it stops — the difference between "try again" and "already done" is the
 *   whole reason the event log exists.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions = new SubscriptionService())
    {
    }

    public function handle(Request $request): never
    {
        $payload = $request->rawBody();
        $signature = $request->headers['Stripe-Signature']
            ?? $request->headers['stripe-signature']
            ?? $_SERVER['HTTP_STRIPE_SIGNATURE']
            ?? '';
        $secret = trim((string) Env::get('STRIPE_WEBHOOK_SECRET', ''));

        if ($payload === '' || $signature === '' || $secret === '') {
            Response::json(['error' => 'Invalid webhook payload.'], 400);
        }

        try {
            // Throws on a bad signature, a replayed timestamp, or a tampered body.
            $event = Webhook::constructEvent($payload, (string) $signature, $secret);
        } catch (\UnexpectedValueException | SignatureVerificationException $exception) {
            error_log('[Duely] Stripe webhook signature rejected: ' . $exception->getMessage());
            Response::json(['error' => 'Webhook signature verification failed.'], 400);
        }

        try {
            $result = $this->subscriptions->handleEvent(
                json_decode($payload, true) ?? [],
                $payload
            );
        } catch (Throwable $exception) {
            // A genuine processing failure. 500 so Stripe retries — the event
            // log has it marked failed, and the retry will find it unclaimed.
            error_log('[Duely] Stripe webhook processing failed: ' . $exception->getMessage());
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
