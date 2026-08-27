<?php
/**
 * The operator panel's chrome.
 *
 * An internal tool. Dense, plain, information first — not a customer surface,
 * and deliberately not styled like one, so there is never a moment of confusion
 * about which of the two you are looking at.
 *
 * Theme tokens throughout: the old super-admin views were still on Keel's
 * `text-gray-500` literals, which rendered as a white card with near-black text
 * in dark mode and looked like a different product entirely.
 *
 * Usage: set $panelTitle, $panelSubtitle, $superAdminNav, then
 *   require __DIR__ . '/_layout.php';   ... page body ...   require __DIR__ . '/_layout-end.php';
 */

$panelTitle = $panelTitle ?? 'Operator';
$panelSubtitle = $panelSubtitle ?? '';
$superAdminNav = $superAdminNav ?? [];

$panelPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$e = $e ?? static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-7xl px-4 py-6">

        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 border-b border-card-border pb-4">
            <div class="flex flex-wrap items-center gap-4">
                <span class="rounded bg-danger-soft px-2 py-1 text-xs font-bold uppercase tracking-wide text-danger-text">
                    Operator
                </span>
                <nav aria-label="Operator" class="flex flex-wrap items-center gap-1">
                    <?php foreach ($superAdminNav as $label => $href): ?>
                    <?php
                    // /super-admin is the landing page, so it only lights up on
                    // an exact match -- a prefix rule would light it on every
                    // page in the panel.
                    $active = $href === '/super-admin'
                        ? $panelPath === $href
                        : ($panelPath === $href || str_starts_with($panelPath, $href . '/'));
                    ?>
                    <a href="<?= $e($href) ?>"
                       class="rounded-md px-2 py-1 text-sm transition <?= $active
                           ? 'bg-surface-muted font-semibold text-brand'
                           : 'text-text-muted hover:text-text-strong' ?> focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                       <?= $active ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <a href="/dashboard" class="text-sm text-text-muted hover:text-text-strong">Back to Duely</a>
                <?php require __DIR__ . '/../partials/sign-out.php'; ?>
            </div>
        </div>

        <div class="mb-5">
            <h1 class="text-2xl font-bold text-text-strong"><?= $e($panelTitle) ?></h1>
            <?php if ($panelSubtitle !== ''): ?>
            <p class="mt-1 text-sm text-text-muted"><?= $e($panelSubtitle) ?></p>
            <?php endif; ?>
        </div>
