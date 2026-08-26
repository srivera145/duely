<?php
/**
 * Duely — sequence list.
 *
 * Most people land here once, read the ladder, decide it sounds right, and
 * leave. The page is written to make that a fast, confident decision.
 */
$sequences = $sequences ?? [];
$hasAny = $hasAny ?? false;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$toneLabels = [
    'polite' => 'Polite',
    'neutral' => 'Neutral',
    'firm' => 'Firm',
    'final' => 'Final',
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-4xl px-4 py-10">

        <?php
        ob_start(); ?>
                <p class="mt-2 max-w-2xl text-sm text-text-muted">
                    When an invoice goes past its due date, Duely works down this ladder — a gentle nudge first,
                    a firmer note later. Most people leave it exactly as it is.
                </p>
        <?php $pageSubtitle = ob_get_clean();
        ob_start(); ?>
            <a href="/invoices" class="text-sm text-text-muted hover:text-text-strong">Back to invoices</a>
        <?php $pageActions = ob_get_clean();
        $pageTitle = 'Reminder sequences';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <?php if (!$hasAny): ?>
        <div class="rounded-xl border border-card-border bg-card p-10">
            <div class="empty-state">
                <p class="empty-state-title">No sequences yet</p>
                <p class="empty-state-text">
                    Every workspace normally starts with Duely's default ladder. Yours is missing — restore it here.
                </p>
                <button type="button" data-restore-default class="btn btn-primary mt-4">
                    Restore the default ladder
                </button>
            </div>
        </div>
        <?php else: ?>

        <div class="space-y-4">
            <?php foreach ($sequences as $sequence): ?>
            <?php $isDefault = (int) $sequence['is_default'] === 1; ?>
            <a href="/sequences/<?= (int) $sequence['id'] ?>"
               class="block rounded-xl border border-card-border bg-card p-6 transition hover:border-brand">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-text-strong"><?= $e($sequence['name']) ?></h2>
                            <?php if ($isDefault): ?>
                            <span class="rounded-full border border-success-border bg-success-soft px-2 py-0.5 text-xs font-medium text-success-text">
                                Default
                            </span>
                            <?php endif; ?>
                            <?php if ((int) $sequence['is_active'] !== 1): ?>
                            <span class="rounded-full border border-card-border px-2 py-0.5 text-xs text-text-muted">Paused</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($sequence['description'])): ?>
                        <p class="mt-1 text-sm text-text-muted"><?= $e($sequence['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="text-sm text-text-muted">
                        <?= (int) $sequence['step_count'] ?> reminder<?= (int) $sequence['step_count'] === 1 ? '' : 's' ?>
                    </span>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-text-muted">
                    <span>
                        Sends between
                        <span class="text-text"><?= $e(substr((string) $sequence['send_window_start'], 0, 5)) ?></span>
                        and
                        <span class="text-text"><?= $e(substr((string) $sequence['send_window_end'], 0, 5)) ?></span>
                    </span>
                    <span><?= (int) $sequence['skip_weekends'] === 1 ? 'Weekends skipped' : 'Sends any day' ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button type="button" data-restore-default class="btn btn-secondary border border-card-border">
                Add a fresh copy of the default ladder
            </button>
        </div>
        <?php endif; ?>

        <div id="sequence-error" class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
    </div>
</body>
</html>
