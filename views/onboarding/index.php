<?php
/**
 * Duely — guided first run.
 *
 * Four steps in the only order that works. Each one links to the real screen
 * that does the job; the wizard tracks progress but never blocks anything.
 */
$progress = $progress ?? ['steps' => [], 'complete' => false, 'percent' => 0, 'completed_count' => 0, 'total' => 4];
$status = $status ?? [];
$founding = $founding ?? ['remaining' => 0, 'total' => 50];
$sequence = $sequence ?? null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-3xl px-4 py-10" id="onboarding">

        <div class="mb-8">
            <p class="text-sm text-text-muted">Duely</p>
            <h1 class="text-3xl font-bold text-text-strong">
                <?= $progress['complete'] ? 'You are all set' : 'Getting started' ?>
            </h1>
            <p class="mt-2 max-w-xl text-sm text-text-muted">
                <?= $progress['complete']
                    ? 'Duely is chasing your overdue invoices. You can close this page.'
                    : 'Four steps and Duely starts following up for you. You can stop and come back at any point.' ?>
            </p>
        </div>

        <!-- Progress -->
        <div class="mb-8">
            <div class="flex items-center justify-between text-sm">
                <span class="text-text-muted">
                    <?= (int) $progress['completed_count'] ?> of <?= (int) $progress['total'] ?> done
                </span>
                <span class="text-text-muted"><?= (int) $progress['percent'] ?>%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-muted">
                <div class="h-full rounded-full bg-brand transition-all" style="width: <?= (int) $progress['percent'] ?>%"></div>
            </div>
        </div>

        <!-- Trial / founding banner -->
        <?php if (!($status['has_subscription'] ?? false)): ?>
        <div class="mb-8 rounded-xl border border-card-border bg-card p-5">
            <?php if ($status['on_trial'] ?? false): ?>
            <p class="font-semibold text-text-strong">
                <?= (int) $status['trial_days_left'] ?> days left on your trial
            </p>
            <p class="mt-1 text-sm text-text-muted">
                Everything is switched on. No card needed until it ends.
            </p>
            <?php elseif ($founding['remaining'] > 0): ?>
            <p class="font-semibold text-text-strong">
                <?= (int) $founding['remaining'] ?> founding places left
            </p>
            <p class="mt-1 text-sm text-text-muted">
                The first <?= (int) $founding['total'] ?> accounts pay $19 a month for as long as they stay —
                that price never goes up, whatever we charge later.
            </p>
            <?php else: ?>
            <p class="font-semibold text-text-strong">Start with a 14-day trial</p>
            <p class="mt-1 text-sm text-text-muted">No card up front.</p>
            <?php endif; ?>

            <div class="mt-4 flex flex-wrap gap-3">
                <?php if (!($status['on_trial'] ?? false) && ($status['trial_ends_at'] ?? null) === null): ?>
                <button type="button" id="start-trial" class="btn btn-primary btn-sm">Start the free trial</button>
                <?php endif; ?>
                <a href="/billing/upgrade" class="btn btn-secondary border border-card-border btn-sm">See the plans</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Steps -->
        <ol class="space-y-4">
            <?php foreach ($progress['steps'] as $step): ?>
            <li class="rounded-xl border p-5 <?= $step['is_current']
                    ? 'border-brand bg-card'
                    : ($step['done'] ? 'border-card-border bg-card/50' : 'border-card-border bg-card') ?>">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 <?=
                            $step['done']
                                ? 'border-success bg-success-soft text-success-text'
                                : ($step['is_current'] ? 'border-brand text-brand' : 'border-card-border text-text-muted') ?>">
                            <?= $step['done'] ? '&check;' : (int) $step['number'] ?>
                        </span>
                        <div>
                            <p class="font-medium <?= $step['done'] ? 'text-text-muted' : 'text-text-strong' ?>">
                                <?= $e($step['title']) ?>
                            </p>
                            <p class="mt-1 text-sm text-text-muted"><?= $e($step['blurb']) ?></p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <?php if ($step['done']): ?>
                        <span class="text-xs text-success-text">Done</span>
                        <?php else: ?>
                        <a href="<?= $e($step['href']) ?>"
                           class="btn btn-sm <?= $step['is_current'] ? 'btn-primary' : 'btn-secondary border border-card-border' ?>">
                            <?= $e($step['action']) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($step['key'] === 'review_sequence' && !$step['done'] && $sequence !== null): ?>
                <div class="mt-4 rounded-lg border border-card-border bg-surface-muted p-4">
                    <p class="text-sm text-text-muted">
                        Duely sends a polite nudge three days after the due date, a firmer note at
                        fourteen, and one last message at thirty. Most people leave it exactly as it is.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="/sequences/<?= (int) $sequence['id'] ?>" class="btn btn-sm border border-card-border">
                            Read the wording
                        </a>
                        <button type="button" id="accept-sequence" class="btn btn-sm btn-primary">
                            Looks good
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>

        <div id="onboarding-error" class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>

        <div class="mt-8 flex flex-wrap items-center gap-4">
            <a href="/dashboard" class="btn btn-secondary border border-card-border">Go to the dashboard</a>
            <?php if (!$progress['complete']): ?>
            <button type="button" id="skip-onboarding" class="text-sm text-text-muted hover:underline">
                Skip this &mdash; I will find my way around
            </button>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
