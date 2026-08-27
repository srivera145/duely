<?php
/**
 * The product navigation. One bar, every signed-in page.
 *
 * It was opt-in until now, and only the dashboard opted in, so every other page
 * was a dead end — a user on the invoice list could not reach Clients without
 * going back through the dashboard first. It is on by default now.
 *
 * Three decisions worth recording:
 *
 * **No sidebar.** Five destinations fit on one line. A sidebar would cost
 * permanent horizontal space on the invoice table plus collapse state, a mobile
 * drawer and focus management, for a nav that does not need any of it.
 *
 * **Active state is derived here, from the request path.** A `$currentSection`
 * passed by each caller would be correct on the day it was written and wrong the
 * first time somebody adds a page and forgets to set it. Child pages resolve to
 * their parent by prefix, so /invoices/12 and /sequences/3/edit light up
 * Invoices and Sequences without either page knowing this file exists.
 *
 * **No JavaScript anywhere in it.** The mobile menu is a <details> element and
 * the grouping is a border, not a dropdown. Navigation that depends on script is
 * navigation that breaks when the script does.
 *
 * Parameters:
 *   $navCompact  bool, default false — drops the logo, for pages that already
 *                render one (the onboarding checklist).
 */

$navCompact = (bool) ($navCompact ?? false);

/**
 * Invoices, Clients and Sequences are the product. Email and Payments are
 * settings for it. Five equal links would say they are the same kind of thing,
 * so the settings pair sits after a divider.
 */
$navSections = [
    'product' => [
        'Dashboard' => '/dashboard',
        'Invoices' => '/invoices',
        'Clients' => '/clients',
        'Sequences' => '/sequences',
    ],
    'settings' => [
        'Email' => '/settings/email',
        'Payments' => '/settings/payments',
    ],
];

// The path only. A query string must not change which link is lit, or
// /invoices?status=open would look like a different section from /invoices.
$navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navPath = rtrim($navPath, '/') ?: '/';

/**
 * A link owns its subtree: /invoices covers /invoices/12 and /invoices/import.
 * The separator matters — a bare str_starts_with would let /invoices-archive
 * light up Invoices.
 */
$navIsActive = static function (string $href) use ($navPath): bool {
    return $navPath === $href || str_starts_with($navPath, $href . '/');
};

$navLinkClass = static function (bool $active): string {
    return 'rounded-md px-2 py-1 text-sm transition '
        // Colour alone would leave this invisible to anyone who cannot see it,
        // which is why every active link also carries aria-current="page".
        //
        // bg-surface-muted rather than a bg-brand/10 tint: the brand colour is
        // a bare var() in the Tailwind config, so it carries no <alpha-value>
        // placeholder and every bg-brand/N utility is silently dropped at build
        // time. Checked in the compiled CSS, not assumed.
        . ($active
            ? 'bg-surface-muted font-semibold text-brand'
            : 'text-text-muted hover:text-text-strong')
        . ' focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand';
};
?>
<div class="mb-6 border-b border-card-border pb-4">
    <div class="flex items-center justify-between gap-4">
        <?php if (!$navCompact): ?>
        <?php
        $variant = 'mark';
        $size = 'sm';
        $link = true;
        $wordmark = true;
        require __DIR__ . '/logo.php';
        ?>
        <?php else: ?>
        <span></span>
        <?php endif; ?>

        <!-- Desktop. Below md this is replaced by the disclosure underneath. -->
        <nav aria-label="Main" class="hidden items-center gap-1 md:flex">
            <?php foreach ($navSections['product'] as $label => $href): ?>
            <?php $active = $navIsActive($href); ?>
            <a href="<?= $href ?>"
               class="<?= $navLinkClass($active) ?>"
               <?= $active ? 'aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>

            <span class="mx-2 h-4 w-px bg-card-border" aria-hidden="true"></span>

            <?php foreach ($navSections['settings'] as $label => $href): ?>
            <?php $active = $navIsActive($href); ?>
            <a href="<?= $href ?>"
               class="<?= $navLinkClass($active) ?>"
               <?= $active ? 'aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>

            <span class="mx-2 h-4 w-px bg-card-border" aria-hidden="true"></span>

            <?php require __DIR__ . '/sign-out.php'; ?>
        </nav>

        <!--
            Mobile. Six links and a sign-out do not fit at 375px, so they
            collapse. A <details> because it opens and closes on its own: a
            button wired to a class toggle stops working the moment the bundle
            fails to load, and a nav that needs script is a nav that can strand
            somebody on a train.
        -->
        <details class="relative md:hidden">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md px-2 py-1 text-sm text-text-muted transition hover:text-text-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                <span aria-hidden="true" class="text-base leading-none">&#9776;</span>
                Menu
            </summary>

            <nav aria-label="Main" class="absolute right-0 top-full z-20 mt-2 flex w-48 flex-col items-start gap-1 rounded-lg border border-card-border bg-card p-3 shadow-lg">
                <?php foreach ($navSections['product'] as $label => $href): ?>
                <?php $active = $navIsActive($href); ?>
                <a href="<?= $href ?>"
                   class="w-full <?= $navLinkClass($active) ?>"
                   <?= $active ? 'aria-current="page"' : '' ?>><?= $label ?></a>
                <?php endforeach; ?>

                <span class="my-1 h-px w-full bg-card-border" aria-hidden="true"></span>

                <?php foreach ($navSections['settings'] as $label => $href): ?>
                <?php $active = $navIsActive($href); ?>
                <a href="<?= $href ?>"
                   class="w-full <?= $navLinkClass($active) ?>"
                   <?= $active ? 'aria-current="page"' : '' ?>><?= $label ?></a>
                <?php endforeach; ?>

                <span class="my-1 h-px w-full bg-card-border" aria-hidden="true"></span>

                <?php require __DIR__ . '/sign-out.php'; ?>
            </nav>
        </details>
    </div>
</div>
<?php
// The enclosing scope is shared with the calling view, and $link in particular
// collides with a foreach variable in views/docs/_layout.php.
unset($navCompact, $navSections, $navPath, $navIsActive, $navLinkClass, $active);
