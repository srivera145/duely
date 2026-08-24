<?php
/**
 * Duely — plans.
 *
 * Three plans, one of which is only really a plan if you get in early. The
 * founding counter is the honest version of scarcity: it is a real count of
 * real claimed rows, and it stops advertising the moment it hits fifty.
 */
$catalogue = $catalogue ?? [];
$status = $status ?? [];
$founding = $founding ?? ['taken' => 0, 'remaining' => 0, 'total' => 50];
$stripeConfigured = $stripeConfigured ?? false;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => '$' . rtrim(rtrim(number_format($cents / 100, 2, '.', ','), '0'), '.');

$limitLabel = static function ($limit, string $singular, string $plural): string {
    if ($limit === null) {
        return 'Unlimited ' . $plural;
    }

    return $limit . ' ' . ($limit === 1 ? $singular : $plural);
};

$errors = [
    'invalid_plan' => 'That plan is not one we sell.',
    'price_not_configured' => 'Checkout is not configured yet. Nothing was charged.',
    'checkout_failed' => 'Stripe could not open checkout. Nothing was charged.',
    'portal_failed' => 'The billing portal could not be opened.',
];
$error = $errors[(string) ($_GET['error'] ?? '')] ?? null;

$blurbs = [
    'free' => 'Enough to see whether it works for you.',
    'solo' => 'For one person who is tired of writing the follow-up.',
    'studio' => 'For a small team sharing the chasing.',
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-5xl px-4 py-10">

        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-text-muted">Billing</p>
                <h1 class="text-3xl font-bold text-text-strong">Plans</h1>
            </div>
            <a href="/dashboard" class="text-sm text-text-muted hover:underline">Back to the dashboard</a>
        </div>

        <?php if ($error !== null): ?>
        <div class="mb-6 rounded-xl border border-danger-border bg-danger-soft px-4 py-3 text-sm text-danger-text">
            <?= $e($error) ?>
        </div>
        <?php endif; ?>

        <?php if (!$stripeConfigured): ?>
        <div class="mb-6 rounded-xl border border-card-border bg-surface-muted px-4 py-3 text-sm text-text-muted">
            Stripe is not configured on this install, so checkout is switched off.
        </div>
        <?php endif; ?>

        <!-- Where this workspace stands -->
        <div class="mb-8 rounded-xl border border-card-border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm text-text-muted">Current plan</p>
                    <p class="text-lg font-semibold text-text-strong">
                        <?= $e($status['plan_name'] ?? 'Free') ?>
                        <?php if (!empty($status['is_founding'])): ?>
                        <span class="ml-2 rounded-full border border-brand px-2 py-0.5 align-middle text-xs text-brand">
                            Founding member #<?= (int) $status['founding_slot'] ?>
                        </span>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($status['on_trial'])): ?>
                    <p class="mt-1 text-sm text-text-muted">
                        <?= (int) $status['trial_days_left'] ?> days left on your trial. No card needed until it ends.
                    </p>
                    <?php elseif (!empty($status['is_founding'])): ?>
                    <p class="mt-1 text-sm text-text-muted">
                        Your price is locked at <?= $e($money((int) $status['price_cents'])) ?> a month for as
                        long as you stay, whatever we charge later.
                    </p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($status['has_subscription'])): ?>
                <form method="POST" action="/billing/portal">
                    <?= \Keel\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-secondary border border-card-border btn-md">
                        Manage billing
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- The founding cohort -->
        <?php if ($founding['remaining'] > 0 && empty($status['is_founding'])): ?>
        <div class="mb-8 rounded-xl border border-brand bg-brand/5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="font-semibold text-text-strong">
                        <?= (int) $founding['remaining'] ?> of <?= (int) $founding['total'] ?> founding places left
                    </p>
                    <p class="mt-1 max-w-xl text-sm text-text-muted">
                        The first <?= (int) $founding['total'] ?> paid accounts keep $19 a month permanently.
                        We will raise the price later; yours will not move.
                    </p>
                </div>
                <div class="w-40">
                    <div class="h-2 overflow-hidden rounded-full bg-surface-muted">
                        <div class="h-full rounded-full bg-brand"
                             style="width: <?= (int) round(((int) $founding['taken'] / max(1, (int) $founding['total'])) * 100) ?>%"></div>
                    </div>
                    <p class="mt-1 text-right text-xs text-text-muted"><?= (int) $founding['taken'] ?> claimed</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Plans -->
        <div class="grid gap-6 md:grid-cols-3">
            <?php foreach ($catalogue as $key => $plan): ?>
            <?php
                $isCurrent = ($status['effective_plan'] ?? 'free') === $key;
                $foundingHere = !empty($plan['founding_available']);
            ?>
            <div class="flex flex-col rounded-xl border p-6 <?= $foundingHere ? 'border-brand bg-card' : 'border-card-border bg-card' ?>">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-sm font-medium uppercase tracking-wide text-text-muted"><?= $e($plan['name']) ?></p>
                    <?php if ($isCurrent): ?>
                    <span class="rounded-full border border-card-border px-2 py-0.5 text-xs text-text-muted">Current</span>
                    <?php elseif ($foundingHere): ?>
                    <span class="rounded-full bg-brand px-2 py-0.5 text-xs font-medium text-black">Founding price</span>
                    <?php endif; ?>
                </div>

                <p class="mt-4 text-3xl font-bold text-text-strong">
                    <?= (int) $plan['price_cents'] === 0 ? 'Free' : $e($money((int) $plan['price_cents'])) ?>
                    <?php if ((int) $plan['price_cents'] > 0): ?>
                    <span class="text-base font-normal text-text-muted">/mo</span>
                    <?php endif; ?>
                </p>

                <?php if (!empty($plan['is_discounted'])): ?>
                <p class="mt-1 text-sm text-text-muted">
                    <span class="line-through"><?= $e($money((int) $plan['list_price_cents'])) ?></span>
                    locked in for life
                </p>
                <?php endif; ?>

                <p class="mt-3 text-sm text-text-muted"><?= $e($blurbs[$key] ?? '') ?></p>

                <ul class="mt-6 flex-1 space-y-2 text-sm text-text-muted">
                    <li><?= $e($limitLabel($plan['limits']['active_chase'] ?? null, 'active chase', 'active chases')) ?></li>
                    <li><?= $e($limitLabel($plan['limits']['email_account'] ?? null, 'email account', 'email accounts')) ?></li>
                    <li><?= $e($limitLabel($plan['limits']['team_seats'] ?? null, 'seat', 'seats')) ?></li>
                    <li><?= empty($plan['features']['tone_assist']) ? 'Write your own wording' : 'Tone assist for rewriting' ?></li>
                    <li>Reply and bounce detection</li>
                </ul>

                <div class="mt-8">
                    <?php if ($isCurrent): ?>
                    <button type="button" class="btn btn-secondary w-full border border-card-border" disabled>
                        Your plan
                    </button>
                    <?php elseif ($key === 'free'): ?>
                    <p class="text-center text-sm text-text-muted">Always available</p>
                    <?php elseif (!$stripeConfigured): ?>
                    <button type="button" class="btn btn-secondary w-full border border-card-border" disabled>
                        Checkout unavailable
                    </button>
                    <?php else: ?>
                    <form method="POST" action="/billing/checkout">
                        <?= \Keel\Core\Csrf::field() ?>
                        <input type="hidden" name="plan" value="<?= $e($key) ?>">
                        <button type="submit" class="btn w-full <?= $foundingHere ? 'btn-primary' : 'btn-secondary border border-card-border' ?>">
                            <?= $foundingHere ? 'Claim a founding place' : 'Choose ' . $e($plan['name']) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="mt-8 text-center text-sm text-text-muted">
            Cancel whenever you like. Your invoices and history stay where they are &mdash;
            dropping to Free pauses the chases over the limit rather than deleting them.
        </p>
    </div>
</body>
</html>
