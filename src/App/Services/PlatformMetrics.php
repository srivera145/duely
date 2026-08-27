<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Database;

/**
 * How the business is doing.
 *
 * **Aggregates only.** A tenant appears here as a name and a number — signup
 * date, plan, spend — and never as its contents. There is no invoice, client,
 * amount owed or message body anywhere in this class. Knowing a workspace exists
 * and pays £19 a month is business information; knowing who they are chasing for
 * how much is theirs.
 */
class PlatformMetrics
{
    public function __construct(private readonly PlanService $plans = new PlanService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        return [
            'signups' => $this->signupsByWeek(),
            'accounts' => $this->accountMix($now),
            'revenue' => $this->revenue($now),
            'founding' => $this->plans->foundingAvailability(),
            'conversion' => $this->conversion(),
            'churn' => $this->churn($now),
            'stripe' => $this->connectedStripe(),
            'ai_spend' => $this->aiSpendByTenant($now),
        ];
    }

    private function signupsByWeek(int $weeks = 12): array
    {
        return $this->rows(
            'SELECT YEARWEEK(created_at, 3) AS week_key,
                    MIN(DATE(created_at)) AS week_starting,
                    COUNT(*) AS total
             FROM organizations
             GROUP BY week_key
             ORDER BY week_key DESC
             LIMIT ' . max(1, $weeks)
        );
    }

    /**
     * Free, trialing, paid, disabled. Mutually exclusive so the numbers add up
     * to the account count and a discrepancy is visible rather than plausible.
     */
    private function accountMix(DateTimeImmutable $now): array
    {
        $stamp = Clock::toDatabase($now);

        $total = (int) $this->scalar('SELECT COUNT(*) FROM organizations');
        $disabled = (int) $this->scalar('SELECT COUNT(*) FROM organizations WHERE disabled_at IS NOT NULL');

        $paid = (int) $this->scalar(
            'SELECT COUNT(DISTINCT tenant_id) FROM subscriptions WHERE status IN (?, ?)',
            ['active', 'past_due']
        );

        // Trialing means a live trial and no subscription. A workspace that
        // converted mid-trial is paid, not both.
        $trialing = (int) $this->scalar(
            'SELECT COUNT(*) FROM organizations o
             WHERE o.trial_ends_at IS NOT NULL AND o.trial_ends_at > ?
               AND o.disabled_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM subscriptions s WHERE s.tenant_id = o.id AND s.status IN (?, ?)
               )',
            [$stamp, 'active', 'past_due']
        );

        return [
            'total' => $total,
            'paid' => $paid,
            'trialing' => $trialing,
            'free' => max(0, $total - $paid - $trialing - $disabled),
            'disabled' => $disabled,
        ];
    }

    /**
     * MRR from what each subscription is actually priced at.
     *
     * Computed from the plan and the founding flag rather than from a stored
     * number, so a founding member counts at their grandfathered rate instead
     * of at list price.
     */
    private function revenue(DateTimeImmutable $now): array
    {
        $rows = $this->rows(
            'SELECT s.tenant_id, s.plan, o.is_founding, o.name AS tenant_name
             FROM subscriptions s
             INNER JOIN organizations o ON o.id = s.tenant_id
             WHERE s.status IN (?, ?)',
            ['active', 'past_due']
        );

        $mrrCents = 0;
        $byPlan = [];

        foreach ($rows as $row) {
            $cents = $this->plans->priceFor((int) $row['tenant_id'], (string) $row['plan']);
            $mrrCents += $cents;

            $plan = (string) $row['plan'];
            $byPlan[$plan] ??= ['plan' => $plan, 'count' => 0, 'cents' => 0];
            $byPlan[$plan]['count']++;
            $byPlan[$plan]['cents'] += $cents;
        }

        return [
            'mrr_cents' => $mrrCents,
            'mrr_formatted' => MoneyParser::format($mrrCents, 'USD'),
            'by_plan' => array_values($byPlan),
            'paying_accounts' => count($rows),
        ];
    }

    /**
     * How many trials ended up paying.
     *
     * Counted over workspaces that ever started a trial, not over live trials,
     * or the number moves every time somebody signs up and says nothing about
     * conversion.
     */
    private function conversion(): array
    {
        $started = (int) $this->scalar('SELECT COUNT(*) FROM organizations WHERE trial_ends_at IS NOT NULL');

        $converted = (int) $this->scalar(
            'SELECT COUNT(DISTINCT o.id) FROM organizations o
             INNER JOIN subscriptions s ON s.tenant_id = o.id
             WHERE o.trial_ends_at IS NOT NULL AND s.status IN (?, ?)',
            ['active', 'past_due']
        );

        return [
            'trials_started' => $started,
            'converted' => $converted,
            'rate' => $started === 0 ? null : round(($converted / $started) * 100, 1),
        ];
    }

    private function churn(DateTimeImmutable $now): array
    {
        $since = Clock::toDatabase($now->modify('-30 days'));

        $cancelled = (int) $this->scalar(
            'SELECT COUNT(*) FROM subscriptions WHERE status = ? AND updated_at >= ?',
            ['canceled', $since]
        );

        $pending = (int) $this->scalar(
            'SELECT COUNT(*) FROM subscriptions WHERE cancel_at_period_end = 1 AND status IN (?, ?)',
            ['active', 'past_due']
        );

        return ['cancelled_30d' => $cancelled, 'cancelling' => $pending];
    }

    private function connectedStripe(): array
    {
        return [
            'connected' => (int) $this->scalar(
                'SELECT COUNT(*) FROM organizations WHERE stripe_account_id IS NOT NULL'
            ),
            'charges_enabled' => (int) $this->scalar(
                'SELECT COUNT(*) FROM organizations WHERE stripe_account_id IS NOT NULL AND stripe_charges_enabled = 1'
            ),
            'by_mode' => $this->rows(
                'SELECT payment_link_mode, COUNT(*) AS total FROM organizations
                 WHERE stripe_account_id IS NOT NULL GROUP BY payment_link_mode'
            ),
        ];
    }

    /**
     * Claude usage per workspace. Tokens, not prompts.
     */
    private function aiSpendByTenant(DateTimeImmutable $now, int $limit = 25): array
    {
        return $this->rows(
            'SELECT a.tenant_id, o.name AS tenant_name,
                    COUNT(*) AS calls,
                    COALESCE(SUM(a.input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(a.output_tokens), 0) AS output_tokens
             FROM ai_usage a
             LEFT JOIN organizations o ON o.id = a.tenant_id
             WHERE a.created_at >= ?
             GROUP BY a.tenant_id, o.name
             ORDER BY (COALESCE(SUM(a.input_tokens), 0) + COALESCE(SUM(a.output_tokens), 0)) DESC
             LIMIT ' . max(1, $limit),
            [Clock::toDatabase($now->modify('-30 days'))]
        );
    }

    // -------------------------------------------------------------- internals

    private function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchColumn();
    }

    private function rows(string $sql, array $bindings = []): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll() ?: [];
    }
}
