<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\App\Models\EmailAccount;
use Keel\Core\Database;
use Throwable;

/**
 * What a workspace is allowed to do.
 *
 * Every gate in the application goes through canUseFeature(). Nothing else
 * compares a plan name, because the moment two places decide what "Solo" means
 * they start disagreeing — and the one that disagrees quietly is the one that
 * lets a free account send five hundred emails.
 *
 * Trials are treated as the plan they are trialling, not as a fourth plan.
 * Someone on day three of a Solo trial should hit exactly the behaviour they
 * are being asked to pay for.
 */
class PlanService
{
    public const PLAN_FREE = 'free';
    public const PLAN_SOLO = 'solo';
    public const PLAN_STUDIO = 'studio';

    /** Features the rest of the app asks about. */
    public const FEATURE_ACTIVE_CHASE = 'active_chase';
    public const FEATURE_EMAIL_ACCOUNT = 'email_account';
    public const FEATURE_TEAM_SEATS = 'team_seats';
    public const FEATURE_TONE_ASSIST = 'tone_assist';
    public const FEATURE_CUSTOM_SEQUENCE = 'custom_sequence';

    public const TRIAL_DAYS = 14;

    /** What the founding cohort pays, for life. */
    /**
     * What a founding workspace pays, per plan.
     *
     * These are the launch-era list prices. They are identical to the current
     * list prices, which is the point: a founding place is worth nothing today
     * and everything the day prices go up. Raise `price_cents` in PLANS and
     * leave these alone, and the first fifty accounts keep what they were
     * promised.
     *
     * Solo-only grandfathering was the first shape of this, and it had a hole:
     * a founding member who needed a second mailbox paid the new Studio price
     * *and* lost their discount, so growing cost them twice. The promise on the
     * pricing page is "whatever we charge later" — it does not say "unless you
     * upgrade".
     */
    private const FOUNDING_PRICES = [
        self::PLAN_SOLO => 1900,
        self::PLAN_STUDIO => 3900,
    ];

    /**
     * The Stripe price to bill a founding workspace against, per plan. A plan
     * with no configured founding price falls back to its standard one.
     */
    private const FOUNDING_PRICE_ENV = [
        self::PLAN_SOLO => 'STRIPE_PRICE_FOUNDING_MONTHLY',
        self::PLAN_STUDIO => 'STRIPE_PRICE_FOUNDING_STUDIO_MONTHLY',
    ];

    /** @deprecated Use foundingPriceFor(). Kept so existing callers still resolve. */
    public const FOUNDING_PRICE_CENTS = 1900;
    public const FOUNDING_SLOTS = 50;

    /**
     * The plans, and what each one allows.
     *
     * `null` means unlimited. Keeping the limits as data rather than as
     * scattered conditionals is what makes canUseFeature() the only gate.
     */
    private const PLANS = [
        self::PLAN_FREE => [
            'name' => 'Free',
            'price_cents' => 0,
            'limits' => [
                self::FEATURE_ACTIVE_CHASE => 3,
                self::FEATURE_EMAIL_ACCOUNT => 1,
                self::FEATURE_TEAM_SEATS => 1,
            ],
            'features' => [
                // Tone assist is on for everyone. The brief's plan differences
                // are chases, mailboxes, and seats; the control on the API cost
                // is the twenty-calls-a-day cap, not the plan. The gate stays
                // here so that decision has one place to change.
                self::FEATURE_TONE_ASSIST => true,
                self::FEATURE_CUSTOM_SEQUENCE => true,
            ],
        ],
        self::PLAN_SOLO => [
            'name' => 'Solo',
            'price_cents' => 1900,
            'limits' => [
                self::FEATURE_ACTIVE_CHASE => null,
                self::FEATURE_EMAIL_ACCOUNT => 1,
                self::FEATURE_TEAM_SEATS => 1,
            ],
            'features' => [
                self::FEATURE_TONE_ASSIST => true,
                self::FEATURE_CUSTOM_SEQUENCE => true,
            ],
        ],
        self::PLAN_STUDIO => [
            'name' => 'Studio',
            'price_cents' => 3900,
            'limits' => [
                self::FEATURE_ACTIVE_CHASE => null,
                self::FEATURE_EMAIL_ACCOUNT => 3,
                self::FEATURE_TEAM_SEATS => 5,
            ],
            'features' => [
                self::FEATURE_TONE_ASSIST => true,
                self::FEATURE_CUSTOM_SEQUENCE => true,
            ],
        ],
    ];

    // --------------------------------------------------------------- the gate

    /**
     * May this workspace use this feature right now?
     *
     * For a counted feature the answer accounts for what is already in use, so
     * the caller asks "may I add one more" rather than having to know the cap.
     *
     * @return array{allowed:bool, reason:?string, used:int, limit:?int, plan:string, upgrade_to:?string}
     */
    public function canUseFeature(int $tenantId, string $feature, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $plan = $this->effectivePlan($tenantId, $now);
        $definition = self::PLANS[$plan] ?? self::PLANS[self::PLAN_FREE];

        // Boolean features first.
        if (array_key_exists($feature, $definition['features'])) {
            $allowed = (bool) $definition['features'][$feature];

            return [
                'allowed' => $allowed,
                'reason' => $allowed ? null : $this->featureReason($feature, $plan),
                'used' => 0,
                'limit' => null,
                'plan' => $plan,
                'upgrade_to' => $allowed ? null : $this->upgradeTarget($plan, $feature),
            ];
        }

        if (!array_key_exists($feature, $definition['limits'])) {
            // An unknown feature is allowed rather than silently blocked: a
            // typo in a call site should not quietly disable something.
            return [
                'allowed' => true, 'reason' => null, 'used' => 0,
                'limit' => null, 'plan' => $plan, 'upgrade_to' => null,
            ];
        }

        $limit = $definition['limits'][$feature];
        $used = $this->usage($tenantId, $feature);

        if ($limit === null || $used < $limit) {
            return [
                'allowed' => true, 'reason' => null, 'used' => $used,
                'limit' => $limit, 'plan' => $plan, 'upgrade_to' => null,
            ];
        }

        $upgradeTo = $this->upgradeTarget($plan, $feature);

        return [
            'allowed' => false,
            'reason' => $this->limitReason($feature, $limit, $plan, $upgradeTo),
            'used' => $used,
            'limit' => $limit,
            'plan' => $plan,
            'upgrade_to' => $upgradeTo,
        ];
    }

    /**
     * The shorthand, for call sites that only need yes or no.
     */
    public function allows(int $tenantId, string $feature, ?DateTimeImmutable $now = null): bool
    {
        return $this->canUseFeature($tenantId, $feature, $now)['allowed'];
    }

    // ------------------------------------------------------------ plan state

    /**
     * The plan a workspace behaves as, which is not always the plan it pays for.
     *
     * A live trial behaves as the plan being trialled; an expired trial falls
     * back to Free rather than leaving someone on a paid plan they never paid
     * for.
     */
    public function effectivePlan(int $tenantId, ?DateTimeImmutable $now = null): string
    {
        $now ??= Clock::now();
        $organization = $this->organization($tenantId);

        if ($organization === null) {
            return self::PLAN_FREE;
        }

        $plan = (string) ($organization['plan'] ?? self::PLAN_FREE);

        if (!isset(self::PLANS[$plan])) {
            return self::PLAN_FREE;
        }

        if ($plan === self::PLAN_FREE) {
            return self::PLAN_FREE;
        }

        // A paid subscription outranks the trial clock entirely.
        if ($this->hasActiveSubscription($tenantId)) {
            return $plan;
        }

        $trialEnds = Clock::fromDatabase($organization['trial_ends_at'] ?? null);

        if ($trialEnds !== null && $trialEnds > $now) {
            return $plan;
        }

        return self::PLAN_FREE;
    }

    /**
     * Everything the billing screen and the plan banner need.
     *
     * @return array{
     *     plan:string, plan_name:string, effective_plan:string, price_cents:int,
     *     on_trial:bool, trial_days_left:?int, trial_ends_at:?string,
     *     is_founding:bool, founding_slot:?int, has_subscription:bool,
     *     limits:array, usage:array
     * }
     */
    public function status(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $organization = $this->organization($tenantId) ?? [];

        $billedPlan = (string) ($organization['plan'] ?? self::PLAN_FREE);
        $effective = $this->effectivePlan($tenantId, $now);
        $trialEnds = Clock::fromDatabase($organization['trial_ends_at'] ?? null);
        $hasSubscription = $this->hasActiveSubscription($tenantId);
        $onTrial = !$hasSubscription && $trialEnds !== null && $trialEnds > $now;

        $definition = self::PLANS[$effective] ?? self::PLANS[self::PLAN_FREE];

        $usage = [];
        foreach (array_keys($definition['limits']) as $feature) {
            $usage[$feature] = [
                'used' => $this->usage($tenantId, $feature),
                'limit' => $definition['limits'][$feature],
            ];
        }

        return [
            'plan' => $billedPlan,
            'plan_name' => (string) $definition['name'],
            'effective_plan' => $effective,
            'price_cents' => $this->priceFor($tenantId, $billedPlan),
            'on_trial' => $onTrial,
            'trial_days_left' => $onTrial ? max(0, (int) $now->diff($trialEnds)->format('%a')) : null,
            'trial_ends_at' => $organization['trial_ends_at'] ?? null,
            'is_founding' => (bool) ($organization['is_founding'] ?? false),
            'founding_slot' => isset($organization['founding_slot']) ? (int) $organization['founding_slot'] : null,
            'has_subscription' => $hasSubscription,
            'limits' => $definition['limits'],
            'usage' => $usage,
        ];
    }

    /**
     * What this workspace actually pays for a plan.
     *
     * A founding workspace keeps $19 whatever the list price becomes later —
     * that is the entire promise of the cohort, so it lives here rather than in
     * a price lookup someone might forget to check.
     */
    public function priceFor(int $tenantId, string $plan): int
    {
        $definition = self::PLANS[$plan] ?? self::PLANS[self::PLAN_FREE];
        $listPrice = (int) $definition['price_cents'];

        if ($plan === self::PLAN_FREE) {
            return 0;
        }

        $organization = $this->organization($tenantId);

        if ($organization !== null && (bool) $organization['is_founding']) {
            $founding = self::foundingPriceFor($plan);

            // A founding price above list would mean prices had gone *down*.
            // Charging the higher of the two would be a punishment for having
            // signed up early, so take whichever is lower.
            if ($founding !== null) {
                return min($founding, $listPrice);
            }
        }

        return $listPrice;
    }

    // -------------------------------------------------------------- trialing

    /**
     * Put a workspace on a trial of a paid plan. No card involved.
     */
    public function startTrial(int $tenantId, string $plan = self::PLAN_SOLO, ?DateTimeImmutable $now = null): bool
    {
        $now ??= Clock::now();

        if (!isset(self::PLANS[$plan]) || $plan === self::PLAN_FREE) {
            return false;
        }

        $organization = $this->organization($tenantId);

        // One trial per workspace: an existing trial date means it has had one.
        if ($organization === null || $organization['trial_ends_at'] !== null) {
            return false;
        }

        $statement = Database::connection()->prepare(
            'UPDATE organizations SET plan = ?, trial_ends_at = ? WHERE id = ? AND trial_ends_at IS NULL'
        );
        $statement->execute([
            $plan,
            Clock::toDatabase($now->modify('+' . self::TRIAL_DAYS . ' days')),
            $tenantId,
        ]);

        return $statement->rowCount() > 0;
    }

    // ------------------------------------------------------ founding cohort

    /**
     * Claim one of the fifty founding slots, if any remain.
     *
     * The claim is a single conditional UPDATE. Two concurrent signups cannot
     * take the same slot because the database serialises the row lock, and once
     * fifty rows are claimed the UPDATE simply matches nothing — so the fifty
     * first signup gets standard pricing rather than becoming number 51.
     *
     * @return array{claimed:bool, slot:?int, reason:?string}
     */
    public function claimFoundingSlot(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        return Database::transaction(function () use ($tenantId, $now): array {
            $connection = Database::connection();

            $existing = $connection->prepare('SELECT slot_number FROM founding_slots WHERE tenant_id = ? LIMIT 1');
            $existing->execute([$tenantId]);
            $held = $existing->fetchColumn();

            if ($held !== false) {
                return ['claimed' => true, 'slot' => (int) $held, 'reason' => 'Already a founding member.'];
            }

            // The atomic bit. LIMIT 1 with an explicit order takes exactly one
            // free row under a lock; a loser of the race updates nothing.
            $claim = $connection->prepare(
                'UPDATE founding_slots
                 SET tenant_id = ?, claimed_at = ?
                 WHERE tenant_id IS NULL
                 ORDER BY slot_number ASC
                 LIMIT 1'
            );
            $claim->execute([$tenantId, Clock::toDatabase($now)]);

            if ($claim->rowCount() === 0) {
                return [
                    'claimed' => false,
                    'slot' => null,
                    'reason' => 'All ' . self::FOUNDING_SLOTS . ' founding places have been taken.',
                ];
            }

            $lookup = $connection->prepare('SELECT slot_number FROM founding_slots WHERE tenant_id = ? LIMIT 1');
            $lookup->execute([$tenantId]);
            $slot = (int) $lookup->fetchColumn();

            $mark = $connection->prepare(
                'UPDATE organizations SET is_founding = 1, founding_slot = ? WHERE id = ?'
            );
            $mark->execute([$slot, $tenantId]);

            return ['claimed' => true, 'slot' => $slot, 'reason' => null];
        });
    }

    /**
     * The launch-era price for a plan, or null if it has none.
     */
    public static function foundingPriceFor(string $plan): ?int
    {
        return self::FOUNDING_PRICES[$plan] ?? null;
    }

    /**
     * The env key holding the grandfathered Stripe price id for a plan.
     */
    public static function foundingPriceEnvKey(string $plan): ?string
    {
        return self::FOUNDING_PRICE_ENV[$plan] ?? null;
    }

    /**
     * @return array{taken:int, remaining:int, total:int}
     */
    public function foundingAvailability(): array
    {
        $taken = (int) Database::connection()
            ->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NOT NULL')
            ->fetchColumn();

        return [
            'taken' => $taken,
            'remaining' => max(0, self::FOUNDING_SLOTS - $taken),
            'total' => self::FOUNDING_SLOTS,
        ];
    }

    // ------------------------------------------------------------ downgrade

    /**
     * Bring a workspace onto a new plan, pausing whatever no longer fits.
     *
     * A downgrade never deletes. Chases over the new limit are paused, newest
     * first — the oldest are the ones closest to being paid, so they are the
     * ones worth keeping running — and the caller is told exactly which, so the
     * user can be shown rather than left to discover it.
     *
     * @return array{plan:string, paused:array<int, array{chase_id:int, invoice_number:string, client_name:string}>}
     */
    public function applyPlan(int $tenantId, string $plan, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $plan = isset(self::PLANS[$plan]) ? $plan : self::PLAN_FREE;

        return Database::transaction(function () use ($tenantId, $plan, $now): array {
            $connection = Database::connection();

            $update = $connection->prepare('UPDATE organizations SET plan = ? WHERE id = ?');
            $update->execute([$plan, $tenantId]);

            $paused = $this->enforceChaseLimit($tenantId, $plan, $now);

            return ['plan' => $plan, 'paused' => $paused];
        });
    }

    /**
     * Pause chases beyond the plan's limit, newest first.
     *
     * @return array<int, array{chase_id:int, invoice_number:string, client_name:string}>
     */
    public function enforceChaseLimit(int $tenantId, ?string $plan = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $plan ??= $this->effectivePlan($tenantId, $now);
        $limit = self::PLANS[$plan]['limits'][self::FEATURE_ACTIVE_CHASE] ?? null;

        if ($limit === null) {
            return [];
        }

        $connection = Database::connection();

        // Newest first: the oldest chases are furthest along and likeliest to
        // land, so they keep running.
        $select = $connection->prepare(
            'SELECT ch.id, i.number AS invoice_number, c.name AS client_name
             FROM chases ch
             INNER JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = ch.tenant_id
             INNER JOIN clients c ON c.id = i.client_id AND c.tenant_id = ch.tenant_id
             WHERE ch.tenant_id = ? AND ch.status IN (?, ?)
             ORDER BY ch.started_at DESC, ch.id DESC'
        );
        $select->execute([$tenantId, Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE]);

        $active = $select->fetchAll();
        $excess = array_slice($active, 0, max(0, count($active) - $limit));
        $paused = [];

        foreach ($excess as $row) {
            Chase::pause($tenantId, (int) $row['id'], Chase::PAUSE_MANUAL);

            $paused[] = [
                'chase_id' => (int) $row['id'],
                'invoice_number' => (string) $row['invoice_number'],
                'client_name' => (string) $row['client_name'],
            ];
        }

        return $paused;
    }

    // ------------------------------------------------------------- catalogue

    /**
     * The plans as the pricing page shows them, with this workspace's price.
     */
    public function catalogue(int $tenantId): array
    {
        $availability = $this->foundingAvailability();
        $organization = $this->organization($tenantId);
        $isFounding = (bool) ($organization['is_founding'] ?? false);

        $catalogue = [];

        foreach (self::PLANS as $key => $definition) {
            $price = $this->priceFor($tenantId, $key);

            $catalogue[$key] = [
                'key' => $key,
                'name' => $definition['name'],
                'price_cents' => $price,
                'list_price_cents' => (int) $definition['price_cents'],
                'is_discounted' => $price < (int) $definition['price_cents'],
                'limits' => $definition['limits'],
                'features' => $definition['features'],
                // The offer is only advertised while places remain, and only to
                // someone who does not already hold one.
                'founding_available' => self::foundingPriceFor($key) !== null
                    && !$isFounding
                    && $availability['remaining'] > 0,
                // Already holding a place, on a plan the place covers.
                'founding_locked' => $isFounding && self::foundingPriceFor($key) !== null,
            ];
        }

        return $catalogue;
    }

    public static function planNames(): array
    {
        return array_keys(self::PLANS);
    }

    // ------------------------------------------------------------- internals

    /**
     * How much of a counted feature is in use.
     */
    private function usage(int $tenantId, string $feature): int
    {
        // Statuses are bound rather than written into the string: double
        // quotes are string literals only while ANSI_QUOTES is off, and a
        // server that has it on would read them as column names.
        [$sql, $bindings] = match ($feature) {
            self::FEATURE_ACTIVE_CHASE => [
                'SELECT COUNT(*) FROM chases WHERE tenant_id = ? AND status IN (?, ?)',
                [$tenantId, Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE],
            ],
            self::FEATURE_EMAIL_ACCOUNT => [
                'SELECT COUNT(*) FROM email_accounts WHERE tenant_id = ? AND status <> ?',
                [$tenantId, EmailAccount::STATUS_DISABLED],
            ],
            self::FEATURE_TEAM_SEATS => [
                'SELECT COUNT(*) FROM users WHERE organization_id = ?',
                [$tenantId],
            ],
            default => [null, []],
        };

        if ($sql === null) {
            return 0;
        }

        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /**
     * Is Stripe still collecting for this workspace?
     *
     * `past_due` counts. Stripe retries a failed card for days before giving
     * up, and taking someone's plan away on the first decline would pause
     * chases over a card that is about to go through. When Stripe does give
     * up it sends a cancellation, and that is what downgrades.
     */
    private function hasActiveSubscription(int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM subscriptions
             WHERE tenant_id = ? AND status IN (?, ?, ?)
             LIMIT 1'
        );
        $statement->execute([$tenantId, 'active', 'trialing', 'past_due']);

        return (bool) $statement->fetchColumn();
    }

    private function organization(int $tenantId): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $statement->execute([$tenantId]);

        return $statement->fetch() ?: null;
    }

    /**
     * The cheapest plan that lifts this restriction.
     */
    private function upgradeTarget(string $currentPlan, string $feature): ?string
    {
        foreach ([self::PLAN_SOLO, self::PLAN_STUDIO] as $candidate) {
            if ($candidate === $currentPlan) {
                continue;
            }

            $definition = self::PLANS[$candidate];

            if (array_key_exists($feature, $definition['features'])) {
                if ($definition['features'][$feature]) {
                    return $candidate;
                }

                continue;
            }

            $limit = $definition['limits'][$feature] ?? null;
            $currentLimit = self::PLANS[$currentPlan]['limits'][$feature] ?? 0;

            if ($limit === null || $limit > $currentLimit) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Why the answer was no, in a sentence a user can act on.
     *
     * The plan that would lift the limit is named rather than left as "upgrade",
     * because someone told to upgrade still has to go and work out to what.
     */
    private function limitReason(string $feature, int $limit, string $plan, ?string $upgradeTo = null): string
    {
        $planName = self::PLANS[$plan]['name'];
        $target = $upgradeTo === null ? null : (self::PLANS[$upgradeTo]['name'] ?? null);

        return match ($feature) {
            self::FEATURE_ACTIVE_CHASE => $planName . ' covers ' . $limit . ' invoice'
                . ($limit === 1 ? '' : 's') . ' being chased at once. Pause one, or move to '
                . ($target ?? 'a paid plan') . ' for unlimited.',
            self::FEATURE_EMAIL_ACCOUNT => $planName . ' includes ' . $limit . ' connected mailbox'
                . ($limit === 1 ? '' : 'es') . '.'
                . ($target === null ? '' : ' ' . $target . ' includes more.'),
            self::FEATURE_TEAM_SEATS => $planName . ' includes ' . $limit . ' seat'
                . ($limit === 1 ? '' : 's') . '.'
                . ($target === null ? '' : ' ' . $target . ' includes more.'),
            default => 'That is not included on ' . $planName . '.',
        };
    }

    private function featureReason(string $feature, string $plan): string
    {
        $planName = self::PLANS[$plan]['name'];

        return match ($feature) {
            self::FEATURE_TONE_ASSIST => 'Writing help is not included on ' . $planName . '.',
            default => 'That is not included on ' . $planName . '.',
        };
    }
}
