<?php
/**
 * Duely — dashboard.
 *
 * One screen, two questions: what is being chased right now, and what came
 * back? Anything needing a decision sorts to the top of the table.
 */
$cards = $cards ?? [];
$chases = $chases ?? [];
$attention = $attention ?? [];
$timezone = $timezone ?? 'UTC';
$hasMailbox = $hasMailbox ?? false;
$onboarding = $onboarding ?? ['complete' => true, 'percent' => 100, 'completed_count' => 4, 'total' => 4, 'current' => null, 'skipped' => false];
$user = $user ?? [];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents, string $currency = 'USD'): string => \Keel\App\Services\MoneyParser::format($cents, $currency);

$averageDays = $cards['average_days_to_payment'] ?? null;
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-7xl px-4 py-10">

        <?php
        // Only the primary action is passed. The nav links live in the
        // partial, and passing them here too would render them twice.
        ob_start(); ?>
            <a href="/invoices/import" class="btn btn-primary btn-sm">Import CSV</a>
        <?php $pageActions = ob_get_clean();
        $pageTitle = 'Dashboard';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <?php if (!$onboarding['complete']): ?>
        <div class="mb-6 rounded-xl border border-card-border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="font-semibold text-text-strong">
                        <?= $onboarding['skipped'] ? 'Pick up where you left off' : 'Finish setting up' ?>
                    </p>
                    <p class="mt-1 text-sm text-text-muted">
                        <?= (int) $onboarding['completed_count'] ?> of <?= (int) $onboarding['total'] ?> steps done.
                        Nothing goes out to a client until the last one.
                    </p>
                    <div class="mt-3 h-1.5 w-56 overflow-hidden rounded-full bg-surface-muted">
                        <div class="h-full rounded-full bg-brand" style="width: <?= (int) $onboarding['percent'] ?>%"></div>
                    </div>
                </div>
                <a href="/onboarding" class="btn btn-primary btn-sm">Continue setup</a>
            </div>
        </div>
        <?php elseif (!$hasMailbox): ?>
        <div class="mb-6 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
            <p class="font-semibold text-amber-400">No mailbox connected yet</p>
            <p class="mt-1 text-sm text-text-muted">
                Duely sends reminders from your own address, so nothing goes out until you connect one.
            </p>
            <a href="/settings/email" class="btn btn-primary btn-sm mt-3">Connect your email</a>
        </div>
        <?php endif; ?>

        <!-- Cards -->
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-card-border bg-card p-5">
                <p class="text-xs uppercase tracking-wide text-text-muted">Outstanding</p>
                <p class="mt-2 text-2xl font-semibold text-text-strong">
                    <?= $e($money((int) ($cards['outstanding_total'] ?? 0), (string) ($cards['outstanding_currency'] ?? 'USD'))) ?>
                </p>
                <?php
                $others = $cards['outstanding'] ?? [];
                unset($others[$cards['outstanding_currency'] ?? 'USD']);
                ?>
                <?php if ($others !== []): ?>
                <p class="mt-1 text-xs text-text-muted">
                    plus
                    <?php foreach ($others as $currency => $cents): ?>
                    <span class="text-text"><?= $e($money((int) $cents, (string) $currency)) ?></span>
                    <?php endforeach; ?>
                </p>
                <?php endif; ?>
            </div>

            <div class="rounded-xl border border-card-border bg-card p-5">
                <p class="text-xs uppercase tracking-wide text-text-muted">Overdue</p>
                <p class="mt-2 text-2xl font-semibold <?= (int) ($cards['overdue_count'] ?? 0) > 0 ? 'text-tone-firm' : 'text-text-strong' ?>">
                    <?= (int) ($cards['overdue_count'] ?? 0) ?>
                </p>
                <p class="mt-1 text-xs text-text-muted">
                    <?= $e($money((int) ($cards['overdue_cents'] ?? 0), (string) ($cards['outstanding_currency'] ?? 'USD'))) ?> past due
                </p>
            </div>

            <div class="rounded-xl border border-card-border bg-card p-5">
                <p class="text-xs uppercase tracking-wide text-text-muted">Average time to pay</p>
                <?php if ($averageDays === null): ?>
                <p class="mt-2 text-2xl font-semibold text-text-muted">—</p>
                <p class="mt-1 text-xs text-text-muted">No invoices paid yet</p>
                <?php else: ?>
                <p class="mt-2 text-2xl font-semibold text-text-strong">
                    <?= $averageDays > 0 ? '+' : '' ?><?= $e($averageDays) ?> days
                </p>
                <p class="mt-1 text-xs text-text-muted">
                    <?= $averageDays > 0 ? 'after' : 'before' ?> the due date,
                    across <?= (int) ($cards['paid_sample'] ?? 0) ?> invoices
                </p>
                <?php endif; ?>
            </div>

            <div class="rounded-xl border border-card-border bg-card p-5">
                <p class="text-xs uppercase tracking-wide text-text-muted">
                    Recovered in <?= (int) ($cards['recovered_window_days'] ?? 30) ?> days
                </p>
                <p class="mt-2 text-2xl font-semibold text-success">
                    <?= $e($money((int) ($cards['recovered_cents'] ?? 0), (string) ($cards['outstanding_currency'] ?? 'USD'))) ?>
                </p>
                <p class="mt-1 text-xs text-text-muted">
                    across <?= (int) ($cards['recovered_count'] ?? 0) ?> invoice<?= (int) ($cards['recovered_count'] ?? 0) === 1 ? '' : 's' ?>
                </p>
            </div>
        </div>

        <!-- What came back -->
        <?php if ($attention !== []): ?>
        <section class="mb-8">
            <h2 class="mb-3 text-lg font-semibold text-text-strong">What came back</h2>
            <div class="space-y-2">
                <?php foreach (array_slice($attention, 0, 5) as $event): ?>
                <div class="rounded-xl border border-card-border bg-card p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-medium text-text-strong">
                            <?= $e($event['client_name'] ?? $event['from_email']) ?>
                            <span class="text-text-muted">
                                <?= $event['type'] === 'bounce' ? 'bounced' : 'replied' ?>
                            </span>
                            <?php if (!empty($event['invoice_number'])): ?>
                            <span class="text-text-muted">on</span>
                            <a href="/invoices/<?= (int) $event['invoice_id'] ?>" class="hover:underline">
                                <?= $e($event['invoice_number']) ?>
                            </a>
                            <?php endif; ?>
                        </p>
                        <span class="text-xs text-text-muted"><?= $e($event['received_at']) ?></span>
                    </div>
                    <?php if (!empty($event['snippet'])): ?>
                    <p class="mt-2 text-sm text-text-muted">"<?= $e($event['snippet']) ?>"</p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Active chases -->
        <section>
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold text-text-strong">Being chased</h2>
                <span class="text-sm text-text-muted">Times shown in <?= $e($timezone) ?></span>
            </div>

            <?php if ($chases === []): ?>
            <div class="rounded-xl border border-card-border bg-card p-10">
                <div class="empty-state">
                    <p class="empty-state-title">Nothing is being chased</p>
                    <p class="empty-state-text">
                        Import your invoices and Duely will start following up on the overdue ones.
                    </p>
                    <a href="/invoices/import" class="btn btn-primary mt-4">Import a CSV</a>
                </div>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto rounded-xl border border-card-border">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Invoice</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Overdue</th>
                            <th>Step</th>
                            <th>Next reminder</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="chase-rows">
                        <?php foreach ($chases as $chase): ?>
                        <tr class="hover:bg-table-row-hover <?= $chase['needs_attention'] ? 'bg-tone-firm-soft' : '' ?>"
                            data-chase-row="<?= (int) $chase['chase_id'] ?>"
                            data-invoice-id="<?= (int) $chase['invoice_id'] ?>">
                            <td>
                                <div class="text-text"><?= $e($chase['client_name']) ?></div>
                                <?php if ($chase['client_company'] !== ''): ?>
                                <div class="text-xs text-text-muted"><?= $e($chase['client_company']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/invoices/<?= (int) $chase['invoice_id'] ?>"
                                   class="font-medium text-text-strong hover:underline">
                                    <?= $e($chase['number']) ?>
                                </a>
                            </td>
                            <td class="text-right font-mono text-text"><?= $e($chase['amount']) ?></td>
                            <td class="text-right whitespace-nowrap">
                                <?php $days = (int) $chase['days_overdue']; ?>
                                <?php if ($days > 0): ?>
                                <span class="font-semibold <?= \Keel\App\Services\ToneRamp::text(\Keel\App\Services\ToneRamp::forDaysOverdue($days)) ?>">
                                    <?= $days ?> day<?= $days === 1 ? '' : 's' ?>
                                </span>
                                <?php elseif ($days === 0): ?>
                                <span class="text-tone-polite">Due today</span>
                                <?php else: ?>
                                <span class="text-text-muted">in <?= abs($days) ?> days</span>
                                <?php endif; ?>
                            </td>
                            <td class="whitespace-nowrap text-text-muted">
                                <?= (int) $chase['step'] ?> of <?= (int) $chase['total_steps'] ?>
                            </td>
                            <td class="whitespace-nowrap text-text-muted" data-next-send>
                                <?php if ($chase['next_send_relative'] !== null): ?>
                                <span title="<?= $e($chase['next_send_local']) ?>"><?= $e($chase['next_send_relative']) ?></span>
                                <?php else: ?>
                                <span class="text-text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="whitespace-nowrap" data-status-cell>
                                <?php
                                $statusClass = match (true) {
                                    $chase['paused_reason'] === 'client_replied' => 'border-success-border bg-success-soft text-success-text',
                                    $chase['needs_attention'] => \Keel\App\Services\ToneRamp::pill(\Keel\App\Services\ToneRamp::FIRM),
                                    default => 'border-card-border bg-surface-muted text-text-muted',
                                };
                                ?>
                                <span class="rounded-full border px-2 py-0.5 text-xs font-medium <?= $statusClass ?>">
                                    <?= $e($chase['status_label']) ?>
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex gap-1">
                                    <?php if ($chase['status'] === 'paused'): ?>
                                    <button type="button" class="btn btn-sm border border-card-border text-xs"
                                            data-chase-action="resume" data-chase-id="<?= (int) $chase['chase_id'] ?>">Resume</button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-sm border border-card-border text-xs"
                                            data-chase-action="pause" data-chase-id="<?= (int) $chase['chase_id'] ?>">Pause</button>
                                    <button type="button" class="btn btn-sm border border-card-border text-xs"
                                            data-chase-action="send-now" data-chase-id="<?= (int) $chase['chase_id'] ?>">Send now</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm border border-card-border text-xs"
                                            data-mark-paid="<?= (int) $chase['invoice_id'] ?>">Mark paid</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <div id="dashboard-error" class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
    </div>

    <!-- Undo toast: mark-paid stays reversible for a few seconds. -->
    <div id="undo-toast"
         class="fixed bottom-6 left-1/2 hidden -translate-x-1/2 items-center gap-4 rounded-xl border border-card-border bg-card px-5 py-3 shadow-lg">
        <span class="text-sm text-text" data-undo-message></span>
        <button type="button" class="text-sm font-semibold text-brand hover:underline" data-undo-button>
            Undo (<span data-undo-countdown>30</span>)
        </button>
    </div>
</body>
</html>
