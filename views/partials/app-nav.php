<?php
/**
 * The authenticated header: the product nav, then the page's own title block.
 *
 * Every signed-in page repeated the same wrapper: a title block on the left, an
 * action block on the right, and a `mb-8 flex flex-wrap items-start
 * justify-between gap-4` around them. That structure is what lived in eight
 * files; this is it, once.
 *
 * Parameters:
 *   $pageTitle        required — the <h1>, already escaped by the caller
 *   $pageEyebrow      small line above the title. Several pages use it as a
 *                     breadcrumb ("Invoices", "Sequences"). It stays even now
 *                     the nav exists: the nav says which section you are in,
 *                     the eyebrow says which record inside it.
 *   $pageEyebrowHref  makes the eyebrow a link.
 *   $pageSubtitle     raw HTML under the title.
 *   $pageActions      raw HTML for the right-hand side, INCLUDING its own
 *                     wrapper. The pages do not agree on that wrapper — some
 *                     use `flex flex-wrap gap-2`, some a bare <a> — and
 *                     imposing one here would respace five of them.
 *   $showNav          bool, default TRUE. It defaulted to false while only the
 *                     dashboard carried nav; every other page was a dead end.
 *                     Opting out is still possible — onboarding/organization.php
 *                     does, because it runs before a workspace exists and every
 *                     destination in the nav would 404 or bounce.
 *
 * Sign out lives in the nav bar, not in $pageActions. It is the same class of
 * thing as the links beside it, and putting it there means a page that opts out
 * of nav has to supply its own — which onboarding/organization.php does.
 */

$pageTitle = $pageTitle ?? '';
$pageEyebrow = $pageEyebrow ?? null;
$pageEyebrowHref = $pageEyebrowHref ?? null;
$pageSubtitle = $pageSubtitle ?? '';
$pageActions = $pageActions ?? '';
$showNav = $showNav ?? true;
?>
<?php if ($showNav): ?>
<?php require __DIR__ . '/nav-bar.php'; ?>
<?php endif; ?>

<div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
        <?php if (!$showNav): ?>
        <div class="mb-2">
            <?php
            $variant = 'mark';
            $size = 'sm';
            $link = true;
            $wordmark = true;
            require __DIR__ . '/logo.php';
            ?>
        </div>
        <?php endif; ?>

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

    <!--
        A single-child flex container adds no spacing, so pages that pass a bare
        <a> as $pageActions look exactly as they did.
    -->
    <div class="flex flex-wrap items-center gap-3">
        <?= $pageActions ?>

        <?php if (!$showNav): ?>
        <?php require __DIR__ . '/sign-out.php'; ?>
        <?php endif; ?>
    </div>
</div>
<?php
// Includes share the enclosing scope; a leftover here would bleed into the next
// page's header if a view ever rendered two.
unset($pageTitle, $pageEyebrow, $pageEyebrowHref, $pageSubtitle, $pageActions, $showNav);
