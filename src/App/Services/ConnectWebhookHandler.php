<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Activity;
use Throwable;

/**
 * What arrives on the Connect endpoint, and what Duely does with it.
 *
 * Every Connect event carries an `account` field naming the connected account
 * it happened on. That field — not the metadata — decides whose workspace this
 * is. Metadata is set when a link is created and travels with the payment; an
 * attacker who could get a payment made with metadata of their choosing would
 * otherwise be able to mark somebody else's invoice paid. So the account
 * resolves the tenant, and the metadata's `duely_tenant_id` has to agree with
 * it or the event is rejected.
 */
class ConnectWebhookHandler
{
    /** Events worth acting on. Everything else is recorded and ignored. */
    private const HANDLED = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'payment_intent.succeeded',
        'charge.refunded',
        'account.updated',
        'account.application.deauthorized',
    ];

    public function __construct(
        private readonly ConnectEventLog $log = new ConnectEventLog(),
        private readonly ConnectService $connect = new ConnectService(),
        private readonly PaymentReceiver $receiver = new PaymentReceiver(),
    ) {
    }

    /**
     * @return array{handled:bool, duplicate:bool, outcome:string, reason:?string}
     */
    public function handle(array $event, string $rawPayload = '', ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $accountId = (string) ($event['account'] ?? '');

        if ($eventId === '' || $type === '') {
            return $this->result(false, false, 'malformed', 'The event had no id or type.');
        }

        // Claimed before any work, so a retry arriving while the first delivery
        // is still running cannot double-apply it.
        $claim = $this->log->claim($eventId, $type, $accountId ?: null, $rawPayload ?: null);

        if (!$claim['claimed']) {
            return $this->result(false, true, 'duplicate', null);
        }

        $logId = (int) $claim['id'];

        try {
            $outcome = $this->dispatch($type, $event, $accountId, $now);
        } catch (Throwable $exception) {
            // Marked failed rather than processed, so Stripe's retry finds it
            // reclaimable instead of silently swallowed.
            $this->log->markFailed($logId, $exception->getMessage());

            throw $exception;
        }

        if ($outcome['handled']) {
            $this->log->markProcessed($logId, $outcome['tenant_id']);
        } else {
            $this->log->markIgnored($logId, $outcome['reason'] ?? 'Not a handled event type.', $outcome['tenant_id']);
        }

        return $this->result($outcome['handled'], false, $outcome['outcome'], $outcome['reason']);
    }

    // -------------------------------------------------------------- internals

    /**
     * @return array{handled:bool, outcome:string, reason:?string, tenant_id:?int}
     */
    private function dispatch(string $type, array $event, string $accountId, DateTimeImmutable $now): array
    {
        if (!in_array($type, self::HANDLED, true)) {
            return $this->step(false, 'unhandled_type', 'Nothing to do for ' . $type . '.');
        }

        // The account is the only trustworthy statement of whose event this is.
        $tenantId = $this->connect->tenantForAccount($accountId);

        if ($tenantId === null) {
            // Usually a workspace that disconnected while an event was in
            // flight. Nothing to apply, and nothing wrong.
            return $this->step(false, 'unknown_account', 'No workspace is connected to that Stripe account.');
        }

        $object = $event['data']['object'] ?? [];

        return match ($type) {
            'account.updated' => $this->accountUpdated($tenantId, $object, $now),
            'account.application.deauthorized' => $this->deauthorized($tenantId),
            'charge.refunded' => $this->refunded($tenantId, $object),
            default => $this->paymentSucceeded($tenantId, $event, $object, $now),
        };
    }

    /**
     * A client paid.
     */
    private function paymentSucceeded(int $tenantId, array $event, array $object, DateTimeImmutable $now): array
    {
        // A checkout session that is not actually paid tells us nothing yet.
        $status = (string) ($object['payment_status'] ?? $object['status'] ?? '');

        if (in_array($status, ['unpaid', 'no_payment_required'], true)) {
            return $this->step(false, 'not_paid', 'That session was not paid.', $tenantId);
        }

        $metadata = $this->metadata($object);
        $amount = $this->amountReceived($object);

        $result = $this->receiver->apply([
            'invoice_id' => (int) ($metadata['duely_invoice_id'] ?? 0),
            'tenant_id' => (int) ($metadata['duely_tenant_id'] ?? 0),
            'amount_cents' => $amount['cents'],
            'currency' => $amount['currency'],
            'event_id' => (string) $event['id'],
            'object_id' => isset($object['id']) ? (string) $object['id'] : null,
        ], $tenantId, $now);

        return $this->step($result['applied'], $result['outcome'], $result['reason'], $tenantId);
    }

    /**
     * The account's standing changed.
     *
     * An account that loses `charges_enabled` must stop producing links. A link
     * that fails at checkout is worse than no link: the client has already
     * decided to pay and been told no.
     */
    private function accountUpdated(int $tenantId, array $object, DateTimeImmutable $now): array
    {
        $this->connect->storeCapabilities(
            $tenantId,
            (bool) ($object['charges_enabled'] ?? false),
            (bool) ($object['payouts_enabled'] ?? false),
            $now
        );

        return $this->step(true, 'account_updated', null, $tenantId);
    }

    /**
     * The user revoked Duely from inside Stripe rather than from inside Duely.
     * Same end state, so the local record is cleared the same way — but without
     * calling deauthorize back at Stripe, which has already happened.
     */
    private function deauthorized(int $tenantId): array
    {
        $this->connect->clearConnection($tenantId);

        return $this->step(true, 'deauthorized', null, $tenantId);
    }

    /**
     * A refund does not un-pay an invoice on its own — the user may have
     * refunded a duplicate charge, or settled the difference some other way.
     * It is recorded and left to a human, for the same reason a part payment is.
     */
    private function refunded(int $tenantId, array $object): array
    {
        $metadata = $this->metadata($object);
        $invoiceId = (int) ($metadata['duely_invoice_id'] ?? 0);

        if ($invoiceId > 0 && (int) ($metadata['duely_tenant_id'] ?? 0) === $tenantId) {
            Activity::log('invoice.refund_seen', 'Invoice', $invoiceId, [
                'amount_refunded_cents' => (int) ($object['amount_refunded'] ?? 0),
            ]);
        }

        return $this->step(true, 'refund_recorded', null, $tenantId);
    }

    /**
     * Metadata can sit on the session, on its payment intent, or on the charge,
     * depending on which event arrived. All three are checked.
     */
    private function metadata(array $object): array
    {
        $candidates = [
            $object['metadata'] ?? null,
            $object['payment_intent']['metadata'] ?? null,
            $object['charge']['metadata'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['duely_invoice_id'])) {
                return $candidate;
            }
        }

        return is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
    }

    /**
     * How much actually arrived.
     *
     * `amount_received` on an intent and `amount_total` on a session are both
     * the settled figure. Reading `amount` first would give the intended amount
     * rather than the paid one, and turn an underpayment into a full settlement.
     */
    private function amountReceived(array $object): array
    {
        $cents = match (true) {
            isset($object['amount_received']) => (int) $object['amount_received'],
            isset($object['amount_total']) => (int) $object['amount_total'],
            isset($object['amount_captured']) => (int) $object['amount_captured'],
            default => (int) ($object['amount'] ?? 0),
        };

        return [
            'cents' => $cents,
            'currency' => strtoupper((string) ($object['currency'] ?? 'usd')),
        ];
    }

    private function step(bool $handled, string $outcome, ?string $reason = null, ?int $tenantId = null): array
    {
        return ['handled' => $handled, 'outcome' => $outcome, 'reason' => $reason, 'tenant_id' => $tenantId];
    }

    private function result(bool $handled, bool $duplicate, string $outcome, ?string $reason): array
    {
        return ['handled' => $handled, 'duplicate' => $duplicate, 'outcome' => $outcome, 'reason' => $reason];
    }
}
