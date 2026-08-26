<?php
/**
 * The authenticated header.
 *
 * Every signed-in page repeated the same wrapper: a title block on the left, an
 * action block on the right, and a `mb-8 flex flex-wrap items-start
 * justify-between gap-4` around them. That structure is what lived in eight
 * files; this is it, once.
 *
 * Parameters:
 *   $pageTitle        required — the <h1>, already escaped by the caller
 *   $pageEyebrow      small line above the title. Four pages use it as a
 *                     breadcrumb ("Invoices", "Sequences") rather than as the
 *                     brand, so it stays caller-supplied — replacing it with
 *                     the logo would delete the only way back up the hierarchy.
 *   $pageEyebrowHref  makes the eyebrow a link.
 *   $pageSubtitle     raw HTML under the title.
 *   $pageActions      raw HTML for the right-hand side, INCLUDING its own
 *                     wrapper. The eight pages do not agree on that wrapper —
 *                     some use `flex flex-wrap gap-2`, some a bare <a> — and
 *                     imposing one here would respace five of them.
 *   $showNav          bool, default false — renders the product nav links.
 *
 * The nav links are opt-in rather than always-on because today only the
 * dashboard carries them. Switching them on everywhere would change what seven
 * pages show, which is a product decision, not a refactor.
 */

$pageTitle = $pageTitle ?? '';
$pageEyebrow = $pageEyebrow ?? null;
$pageEyebrowHref = $pageEyebrowHref ?? null;
$pageSubtitle = $pageSubtitle ?? '';
$pageActions = $pageActions ?? '';
$showNav = $showNav ?? false;

$navLinks = [
    'Invoices' => '/invoices',
    'Clients' => '/clients',
    'Sequences' => '/sequences',
    'Email' => '/settings/email',
    'Payments' => '/settings/payments',
];
?>
<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        <div class="mb-2">
            <?php
            $variant = 'mark';
            $size = 'sm';
            $link = true;
            $wordmark = true;
            require __DIR__ . '/logo.php';
            ?>
        </div>

        <?php if ($pageEyebrow !== null): ?>
        <p class="text-sm text-text-muted">
            <?php if ($pageEyebrowHref !== null): ?>
            <a href="<?= htmlspecialchars($pageEyebrowHref, ENT_QUOTES, 'UTF-8') ?>" class="hover:underline"><?= htmlspecialchars($pageEyebrow, ENT_QUOTES, 'UTF-8') ?></a>
            <?php else: ?>
            <?= htmlspecialchars($pageEyebrow, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <h1 class="text-3xl font-bold text-text-strong"><?= $pageTitle ?></h1>

        <?= $pageSubtitle ?>
    </div>

    <?php if ($showNav): ?>
    <div class="flex flex-wrap items-center gap-3">
        <?php foreach ($navLinks as $label => $href): ?>
        <a href="<?= $href ?>" class="text-sm text-text-muted hover:text-text-strong"><?= $label ?></a>
        <?php endforeach; ?>
        <?= $pageActions ?>
    </div>
    <?php else: ?>
    <?= $pageActions ?>
    <?php endif; ?>
</div>
<?php
// Includes share the enclosing scope; a leftover here would bleed into the next
// page's header if a view ever rendered two.
unset($pageTitle, $pageEyebrow, $pageEyebrowHref, $pageSubtitle, $pageActions,
    $showNav, $navLinks);
