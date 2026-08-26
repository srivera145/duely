<?php
/**
 * Pricing.
 *
 * The same three plans the application enforces. If this page and PlanService
 * ever disagree, this page is the one that is lying.
 */
$founding = $founding ?? null;
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$plans = [
    [
        'name' => 'Free',
        'price' => 'Free',
        'suffix' => '',
        'blurb' => 'Enough to find out whether it works for you.',
        'features' => [
            'Three invoices being chased at once',
            'One connected mailbox',
            'Reply and bounce detection',
            'CSV import',
        ],
        'featured' => false,
    ],
    [
        'name' => 'Solo',
        'price' => '$19',
        'suffix' => '/month',
        'blurb' => 'For one person who is tired of writing the follow-up.',
        'features' => [
            'Unlimited invoices being chased',
            'One connected mailbox',
            'Reply and bounce detection',
            'Writing help for the wording',
        ],
        'featured' => true,
    ],
    [
        'name' => 'Studio',
        'price' => '$39',
        'suffix' => '/month',
        'blurb' => 'For a small team sharing the chasing.',
        'features' => [
            'Unlimited invoices being chased',
            'Three connected mailboxes',
            'Five seats',
            'Everything in Solo',
        ],
        'featured' => false,
    ],
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text antialiased">
<?php $current = 'pricing'; require __DIR__ . '/partials/nav.php'; ?>

<main>
    <section class="mx-auto max-w-6xl px-4 pb-12 pt-12 text-center sm:pt-16">
        <h1 class="text-4xl font-semibold leading-tight tracking-tight text-text-strong sm:text-5xl">
            One invoice paid on time covers a year of it.
        </h1>
        <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-text-muted">
            Start free with no card. Upgrade when three invoices at a time stops being enough.
        </p>

        <?php if ($founding !== null && $founding['remaining'] > 0): ?>
        <?php
            $foundingLabel = $founding['remaining'] === $founding['total']
                ? $founding['total'] . ' founding places left'
                : $founding['remaining'] . ' of ' . $founding['total'] . ' founding places left';
        ?>
        <p class="mx-auto mt-8 inline-flex max-w-full items-center gap-2 rounded-full border border-brand/40 bg-brand/10 px-4 py-2 text-left text-sm text-brand">
            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
            <span><?= $e($foundingLabel) ?> &mdash; they keep today's price for good, on whichever plan they are on</span>
        </p>
        <?php endif; ?>
    </section>

    <section class="mx-auto max-w-6xl px-4 pb-16">
        <div class="grid gap-6 md:grid-cols-3">
            <?php foreach ($plans as $plan): ?>
            <article class="flex flex-col rounded-2xl border p-7 <?= $plan['featured']
                    ? 'border-brand bg-card'
                    : 'border-card-border bg-card' ?>">
                <div class="flex items-start justify-between gap-2">
                    <h2 class="text-sm font-medium uppercase tracking-wide text-text-muted">
                        <?= $e($plan['name']) ?>
                    </h2>
                    <?php if ($plan['price'] !== 'Free' && $founding !== null && $founding['remaining'] > 0): ?>
                    <span class="rounded-full bg-brand px-2.5 py-0.5 text-xs font-semibold text-brand-contrast">
                        Founding price
                    </span>
                    <?php endif; ?>
                </div>

                <p class="mt-4 text-4xl font-semibold tracking-tight text-text-strong">
                    <?= $e($plan['price']) ?><?php if ($plan['suffix'] !== ''): ?><span class="text-base font-normal text-text-muted"><?= $e($plan['suffix']) ?></span><?php endif; ?>
                </p>

                <p class="mt-3 text-text-muted"><?= $e($plan['blurb']) ?></p>

                <ul class="mt-7 flex-1 space-y-3">
                    <?php foreach ($plan['features'] as $feature): ?>
                    <li class="flex gap-3 text-text-muted">
                        <svg viewBox="0 0 16 16" class="mt-1 h-4 w-4 shrink-0 text-brand" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">
                            <path d="M3.5 8.5 6.5 11.5 12.5 4.5" />
                        </svg>
                        <?= $e($feature) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <a href="/#waitlist"
                   class="mt-8 block rounded-lg px-4 py-3 text-center font-semibold transition <?= $plan['featured']
                       ? 'bg-brand text-brand-contrast hover:bg-brand-hover'
                       : 'border border-card-border text-text-strong hover:bg-surface-hover' ?>">
                    Join the waitlist
                </a>
            </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- The honest bits -->
    <section class="border-y border-card-border bg-surface-muted">
        <div class="mx-auto max-w-3xl px-4 py-16">
            <h2 class="text-3xl font-semibold tracking-tight text-text-strong">The small print, up front</h2>

            <dl class="mt-8 space-y-7">
                <?php
                $notes = [
                    'What is a founding place?' => 'The first fifty accounts that start paying keep '
                        . 'today\'s price for as long as they stay subscribed, whatever the price '
                        . 'becomes later — and that holds on whichever plan they are on, so moving '
                        . 'up to Studio never costs you the rate you were given. There are fifty '
                        . 'and there will not be fifty-one.',
                    'What happens if I downgrade?' => 'Nothing is deleted. If you drop below the '
                        . 'limit, Duely pauses the newest chases and tells you exactly which ones. '
                        . 'The oldest keep running — they are the ones closest to being paid.',
                    'Is there a trial?' => 'Fourteen days of the paid plan, no card up front. When '
                        . 'it ends you drop to Free rather than being charged.',
                    'Can I cancel?' => 'Whenever you like, from Stripe. Your invoices, clients and '
                        . 'history stay where they are.',
                    'Do you take a cut of what I invoice?' => 'No. Duely never touches the money. It '
                        . 'sends emails; your client pays you the way they always have.',
                ];
                foreach ($notes as $question => $answer):
                ?>
                <div>
                    <dt class="text-lg font-medium text-text-strong"><?= $e($question) ?></dt>
                    <dd class="mt-2 leading-relaxed text-text-muted"><?= $e($answer) ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
        </div>
    </section>

    <section class="mx-auto max-w-3xl px-4 py-16">
        <div class="rounded-2xl border border-card-border bg-card p-8 sm:p-10">
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Join the waitlist</h2>
            <p class="mt-3 leading-relaxed text-text-muted">
                We will email you once to confirm, then when your place is ready.
            </p>
            <div id="waitlist" class="mt-6 max-w-lg scroll-mt-24">
                <?php
                $source = 'pricing';
                $landingPath = '/pricing';
                require __DIR__ . '/partials/waitlist-form.php';
                ?>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
