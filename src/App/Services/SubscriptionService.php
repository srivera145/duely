<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Activity;
use Keel\Core\Database;
use Keel\Core\Env;
use Throwable;

/**
 * Turns Stripe's view of the world into Duely's.
 *
 * Stripe owns whether money is being collected; Duely owns what that entitles a
 * workspace to. This is the only place the two meet, so the mapping from price
 * id to plan lives here rather than being re-derived wherever it is needed.
 *
 * Every write is idempotent, because webhooks are delivered at least once and
 * "at least" does a lot of work in that sentence.
 */
class SubscriptionService
{
    public function __construct(
        private readonly PlanService $plans = new PlanService(),
        private readonly StripeEventLog $log = new StripeEventLog(),
    ) {
    }

    // ------------------------------------------------------------- webhooks

    /**
     * Handle one verified Stripe event.
     *
     * The signature is checked by the controller before this is called; here we
     * deal with what the event means and with making sure it means it only once.
     *
     * @param array $event the decoded event
     * @return array{handled:bool, duplicate:bool, reason:?string, tenant_id:?int}
     */
    public function handleEvent(array $event, ?string $rawPayload = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($eventId === '' || $type === '') {
            return ['handled' => false, 'duplicate' => false, 'reason' => 'Event is missing an id or type.', 'tenant_id' => null];
        }

        $claim = $this->log->claim($eventId, $type, $rawPayload);

        if (!$claim['claimed']) {
            // A replay. Nothing is applied a second time.
            return [
                'handled' => false,
                'duplicate' => true,
                'reason' => 'Already handled (' . ($claim['previous_status'] ?? 'unknown') . ').',
                'tenant_id' => null,
            ];
        }

        $logId = (int) $claim['id'];

        try {
            $result = $this->apply($type, $event, $now);

            if ($result['handled']) {
                $this->log->markProcessed($logId, $result['tenant_id']);
            } else {
                $this->log->markIgnored($logId, $result['reason'] ?? 'Event type not handled.');
            }

            return $result + ['duplicate' => false];
        } catch (Throwable $exception) {
            $this->log->markFailed($logId, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @return array{handled:bool, reason:?string, tenant_id:?int}
     */
    private function apply(string $type, array $event, DateTimeImmutable $now): array
    {
        $object = $event['data']['object'] ?? [];

        return match ($type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($object, $now),
            'customer.subscription.created',
            'customer.subscription.updated' => $this->onSubscriptionChanged($object, $now),
            'customer.subscription.deleted' => $this->onSubscriptionCancelled($object, $now),
            'invoice.payment_failed' => $this->onPaymentFailed($object, $now),
            default => ['handled' => false, 'reason' => 'Unhandled event type: ' . $type, 'tenant_id' => null],
        };
    }

    /**
     * A checkout finished. This is where a workspace first becomes paying, and
     * therefore where a founding place is claimed.
     */
    private function onCheckoutCompleted(array $session, DateTimeImmutable $now): array
    {
        if (($session['mode'] ?? null) !== 'subscription') {
            return ['handled' => false, 'reason' => 'Not a subscription checkout.', 'tenant_id' => null];
        }

        $userId = $this->userFromMetadata($session);
        $tenantId = $this->tenantFromMetadata($session, $userId);
        $userId ??= $this->ownerId($tenantId);

        if ($userId === null) {
            // Nothing to hang the row on. Reported rather than thrown, so
            // Stripe stops retrying something a retry cannot fix.
            return ['handled' => false, 'reason' => 'No account on the checkout session.', 'tenant_id' => null];
        }

        // Stripe sends `subscription` as an id, or as the whole object when the
        // session was expanded. Both are handled: taking the id from the wrong
        // shape would silently write a row keyed on an empty string.
        $expanded = is_array($session['subscription'] ?? null) ? $session['subscription'] : [];
        $subscriptionId = $this->idOf($session['subscription'] ?? null);
        $customerId = $this->idOf($session['customer'] ?? null);
        $priceId = (string) ($expanded['items']['data'][0]['price']['id'] ?? '');
        $plan = $this->planFrom($session['metadata']['plan'] ?? null, $session, $priceId);

        $row = [
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => $priceId,
            'plan' => $plan,
            'status' => (string) ($expanded['status'] ?? 'active'),
            'current_period_end' => $this->periodEnd($expanded),
            'cancel_at_period_end' => !empty($expanded['cancel_at_period_end']) ? 1 : 0,
        ];

        return Database::transaction(function () use ($tenantId, $userId, $plan, $row, $customerId, $now): array {
            if ($customerId !== '' && $tenantId !== null) {
                $this->storeCustomerId($tenantId, $customerId);
            }

            $this->upsertSubscription($tenantId, $userId, $row);

            if ($tenantId === null) {
                // A single-tenant install: the payment is recorded, but there
                // is no workspace to grant a plan or a founding place to.
                return ['handled' => true, 'reason' => null, 'tenant_id' => null];
            }

            // Claiming here rather than at signup means the cohort is fifty
            // *paying* accounts, which is what was promised.
            $founding = $this->plans->claimFoundingSlot($tenantId, $now);

            $this->plans->applyPlan($tenantId, $plan, $now);

            Activity::log('billing.subscription_started', 'Organization', $tenantId, [
                'plan' => $plan,
                'founding' => $founding['claimed'],
                'slot' => $founding['slot'],
            ]);

            return ['handled' => true, 'reason' => null, 'tenant_id' => $tenantId];
        });
    }

    /**
     * A subscription changed: plan switch, renewal, or a card recovering.
     */
    private function onSubscriptionChanged(array $subscription, DateTimeImmutable $now): array
    {
        [$tenantId, $userId] = $this->accountFor($subscription);

        if ($userId === null) {
            return ['handled' => false, 'reason' => 'No account matches this subscription.', 'tenant_id' => null];
        }

        $priceId = (string) ($subscription['items']['data'][0]['price']['id'] ?? '');
        $plan = $this->planFrom($subscription['metadata']['plan'] ?? null, $subscription, $priceId);
        $status = (string) ($subscription['status'] ?? 'active');

        return Database::transaction(function () use ($tenantId, $userId, $subscription, $priceId, $plan, $status, $now): array {
            $this->upsertSubscription($tenantId, $userId, [
                'stripe_subscription_id' => (string) ($subscription['id'] ?? ''),
                'stripe_price_id' => $priceId,
                'plan' => $plan,
                'status' => $status,
                'current_period_end' => $this->periodEnd($subscription),
                'cancel_at_period_end' => !empty($subscription['cancel_at_period_end']) ? 1 : 0,
            ]);

            if ($tenantId === null) {
                return ['handled' => true, 'reason' => null, 'tenant_id' => null];
            }

            // A lapsed subscription drops the workspace to Free, which is what
            // pauses anything over the free limit. `past_due` is not lapsed:
            // Stripe is still retrying the card, and this must agree with
            // invoice.payment_failed rather than undo it.
            $entitled = in_array($status, ['active', 'trialing', 'past_due'], true);
            $effectivePlan = $entitled ? $plan : PlanService::PLAN_FREE;
            $result = $this->plans->applyPlan($tenantId, $effectivePlan, $now);

            Activity::log('billing.subscription_updated', 'Organization', $tenantId, [
                'plan' => $effectivePlan,
                'status' => $status,
                'paused_chases' => count($result['paused']),
            ]);

            return ['handled' => true, 'reason' => null, 'tenant_id' => $tenantId];
        });
    }

    private function onSubscriptionCancelled(array $subscription, DateTimeImmutable $now): array
    {
        [$tenantId, $userId] = $this->accountFor($subscription);

        if ($userId === null) {
            return ['handled' => false, 'reason' => 'No account matches this subscription.', 'tenant_id' => null];
        }

        return Database::transaction(function () use ($tenantId, $subscription, $now): array {
            $this->setStatus((string) ($subscription['id'] ?? ''), 'canceled');

            if ($tenantId === null) {
                return ['handled' => true, 'reason' => null, 'tenant_id' => null];
            }

            // Down to Free. Chases beyond the limit are paused, never deleted.
            $result = $this->plans->applyPlan($tenantId, PlanService::PLAN_FREE, $now);

            Activity::log('billing.subscription_cancelled', 'Organization', $tenantId, [
                'paused_chases' => count($result['paused']),
            ]);

            return ['handled' => true, 'reason' => null, 'tenant_id' => $tenantId];
        });
    }

    /**
     * A payment failed. The plan is left alone — Stripe retries for days, and
     * dropping someone to Free on the first failed card would pause their
     * chases over a card that is about to succeed.
     */
    private function onPaymentFailed(array $invoice, DateTimeImmutable $now): array
    {
        $subscriptionId = (string) ($invoice['subscription'] ?? '');

        if ($subscriptionId === '') {
            return ['handled' => false, 'reason' => 'Invoice has no subscription.', 'tenant_id' => null];
        }

        $existing = $this->existingSubscription($subscriptionId);

        if ($existing === null) {
            return ['handled' => false, 'reason' => 'No account matches this subscription.', 'tenant_id' => null];
        }

        $this->setStatus($subscriptionId, 'past_due');

        $tenantId = $existing['tenant_id'];

        if ($tenantId !== null) {
            Activity::log('billing.payment_failed', 'Organization', $tenantId);
        }

        return ['handled' => true, 'reason' => null, 'tenant_id' => $tenantId];
    }

    // -------------------------------------------------------------- helpers

    /**
     * Insert or update the workspace's subscription row.
     *
     * Keyed on the Stripe subscription id, so replaying an event rewrites the
     * same row rather than adding another.
     */
    private function upsertSubscription(?int $tenantId, int $userId, array $attributes): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO subscriptions
                (tenant_id, user_id, stripe_subscription_id, stripe_price_id, plan, status,
                 current_period_end, cancel_at_period_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                tenant_id = COALESCE(VALUES(tenant_id), tenant_id),
                stripe_price_id = VALUES(stripe_price_id),
                plan = VALUES(plan),
                status = VALUES(status),
                current_period_end = VALUES(current_period_end),
                cancel_at_period_end = VALUES(cancel_at_period_end)'
        );

        $statement->execute([
            $tenantId,
            $userId,
            $attributes['stripe_subscription_id'],
            $attributes['stripe_price_id'],
            $attributes['plan'],
            $attributes['status'],
            $attributes['current_period_end'],
            $attributes['cancel_at_period_end'],
        ]);
    }

    /**
     * A Stripe reference that may arrive as a bare id or an expanded object.
     */
    private function idOf(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Stripe's period end is a Unix timestamp; the column is UTC-naive.
     */
    private function periodEnd(array $object): ?string
    {
        if (!isset($object['current_period_end'])) {
            return null;
        }

        return Clock::toDatabase(
            (new DateTimeImmutable('@' . (int) $object['current_period_end']))->setTimezone(Clock::utc())
        );
    }

    /**
     * Resolve a plan from metadata, then from the price id, then fall back.
     */
    private function planFrom(mixed $metadataPlan, array $object, string $priceId = ''): string
    {
        $plan = strtolower(trim((string) $metadataPlan));

        if (in_array($plan, PlanService::planNames(), true) && $plan !== PlanService::PLAN_FREE) {
            return $plan;
        }

        $priceId = $priceId !== '' ? $priceId : (string) ($object['items']['data'][0]['price']['id'] ?? '');

        foreach ([
            PlanService::PLAN_SOLO => 'STRIPE_PRICE_SOLO_MONTHLY',
            PlanService::PLAN_STUDIO => 'STRIPE_PRICE_STUDIO_MONTHLY',
        ] as $candidate => $envKey) {
            if ($priceId !== '' && $priceId === trim((string) Env::get($envKey, ''))) {
                return $candidate;
            }
        }

        // A founding price id still means Solo.
        if ($priceId !== '' && $priceId === trim((string) Env::get('STRIPE_PRICE_FOUNDING_MONTHLY', ''))) {
            return PlanService::PLAN_SOLO;
        }

        return PlanService::PLAN_SOLO;
    }

    /**
     * Who this checkout or subscription belongs to.
     *
     * Keel's checkout puts the user id in both `client_reference_id` and
     * `metadata.user_id`, so it is the one identifier always present — the
     * workspace is derived from it rather than the other way round.
     */
    private function userFromMetadata(array $object): ?int
    {
        foreach ([
            $object['metadata']['user_id'] ?? null,
            $object['client_reference_id'] ?? null,
        ] as $candidate) {
            if ($candidate !== null && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * Which workspace this belongs to, if any.
     *
     * Explicit metadata first, then the workspace the paying user is in, then
     * the customer id stored at checkout. All three can legitimately come back
     * empty on a single-tenant install, which is not an error.
     */
    private function tenantFromMetadata(array $object, ?int $userId = null): ?int
    {
        foreach ([
            $object['metadata']['tenant_id'] ?? null,
            $object['metadata']['organization_id'] ?? null,
        ] as $candidate) {
            if ($candidate !== null && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        if ($userId !== null) {
            $tenantId = $this->tenantForUser($userId);

            if ($tenantId !== null) {
                return $tenantId;
            }
        }

        $customerId = (string) ($object['customer'] ?? '');

        return $customerId === '' ? null : $this->tenantForCustomer($customerId);
    }

    /**
     * Workspace and user for a subscription event, falling back to the row the
     * checkout already wrote when the event carries no metadata of its own.
     *
     * @return array{0:?int, 1:?int}
     */
    private function accountFor(array $subscription): array
    {
        $userId = $this->userFromMetadata($subscription);
        $tenantId = $this->tenantFromMetadata($subscription, $userId);

        $existing = $this->existingSubscription((string) ($subscription['id'] ?? ''));

        if ($existing !== null) {
            $userId ??= $existing['user_id'];
            $tenantId ??= $existing['tenant_id'];
        }

        $userId ??= $this->ownerId($tenantId);

        return [$tenantId, $userId];
    }

    /**
     * @return array{tenant_id:?int, user_id:?int}|null
     */
    private function existingSubscription(string $subscriptionId): ?array
    {
        if ($subscriptionId === '') {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT tenant_id, user_id FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1'
        );
        $statement->execute([$subscriptionId]);
        $row = $statement->fetch();

        if (!$row) {
            return null;
        }

        return [
            'tenant_id' => $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
        ];
    }

    /**
     * The Stripe subscription id is unique, so the status is set by it alone
     * rather than by a tenant that may not exist.
     */
    private function setStatus(string $subscriptionId, string $status): void
    {
        if ($subscriptionId === '') {
            return;
        }

        $statement = Database::connection()->prepare(
            'UPDATE subscriptions SET status = ? WHERE stripe_subscription_id = ?'
        );
        $statement->execute([$status, $subscriptionId]);
    }

    private function tenantForUser(int $userId): ?int
    {
        $statement = Database::connection()->prepare(
            'SELECT organization_id FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$userId]);
        $tenantId = $statement->fetchColumn();

        return $tenantId === false || $tenantId === null ? null : (int) $tenantId;
    }

    private function tenantForCustomer(string $customerId): ?int
    {
        $statement = Database::connection()->prepare(
            'SELECT id FROM organizations WHERE stripe_customer_id = ? LIMIT 1'
        );
        $statement->execute([$customerId]);
        $tenantId = $statement->fetchColumn();

        return $tenantId === false ? null : (int) $tenantId;
    }

    private function storeCustomerId(int $tenantId, string $customerId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE organizations SET stripe_customer_id = ? WHERE id = ?'
        );
        $statement->execute([$customerId, $tenantId]);
    }

    private function ownerId(?int $tenantId): ?int
    {
        if ($tenantId === null) {
            return null;
        }

        $statement = Database::connection()->prepare(
            'SELECT id FROM users WHERE organization_id = ? ORDER BY (role = ?) DESC, id ASC LIMIT 1'
        );
        $statement->execute([$tenantId, 'owner']);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
