<?php
/**
 * Tier two. How the business is doing.
 *
 * Aggregates only. A tenant is a name and a number here — never an invoice, a
 * client, or an amount somebody is owed.
 */
$metrics = $metrics ?? [];
$accounts = $metrics['accounts'] ?? [];
$revenue = $metrics['revenue'] ?? [];
$founding = $metrics['founding'] ?? [];
$conversion = $metrics['conversion'] ?? [];
$churn = $metrics['churn'] ?? [];
$stripe = $metrics['stripe'] ?? [];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$panelTitle = 'Business';
require __DIR__ . '/_layout.php';
?>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ([
                ['MRR', $revenue['mrr_formatted'] ?? '$0.00', ($revenue['paying_accounts'] ?? 0) . ' paying'],
                ['Accounts', (string) ($accounts['total'] ?? 0),
                    ($accounts['paid'] ?? 0) . ' paid / ' . ($accounts['trialing'] ?? 0) . ' trialing / '
                    . ($accounts['free'] ?? 0) . ' free'],
                ['Founding', ($founding['taken'] ?? 0) . ' of ' . ($founding['total'] ?? 50),
                    ($founding['remaining'] ?? 0) . ' left'],
                ['Trial to paid', ($conversion['rate'] ?? null) === null ? '—' : $conversion['rate'] . '%',
                    ($conversion['converted'] ?? 0) . ' of ' . ($conversion['trials_started'] ?? 0)],
            ] as [$label, $value, $detail]): ?>
            <div class="rounded-lg border border-card-border bg-card p-4">
                <p class="text-xs uppercase tracking-wide text-text-muted"><?= $e($label) ?></p>
                <p class="mt-1 text-2xl font-bold text-text-strong"><?= $e($value) ?></p>
                <p class="mt-1 text-xs text-text-muted"><?= $e($detail) ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Signups by week</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <tbody>
                            <?php foreach (($metrics['signups'] ?? []) as $row): ?>
                            <tr class="border-b border-card-border">
                                <td class="py-1.5 pr-4 font-mono text-xs text-text-muted">
                                    <?= $e($row['week_starting']) ?>
                                </td>
                                <td class="py-1.5 text-right font-semibold text-text-strong"><?= (int) $row['total'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Revenue and churn</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <?php foreach (($revenue['by_plan'] ?? []) as $row): ?>
                    <dt class="text-text-muted"><?= $e(ucfirst($row['plan'])) ?> (<?= (int) $row['count'] ?>)</dt>
                    <dd class="text-text-strong">
                        <?= $e(\Keel\App\Services\MoneyParser::format((int) $row['cents'], 'USD')) ?>
                    </dd>
                    <?php endforeach; ?>
                    <dt class="text-text-muted">Cancelled (30d)</dt>
                    <dd class="text-text-strong"><?= (int) ($churn['cancelled_30d'] ?? 0) ?></dd>
                    <dt class="text-text-muted">Cancelling</dt>
                    <dd class="text-text-strong"><?= (int) ($churn['cancelling'] ?? 0) ?></dd>
                    <dt class="text-text-muted">Disabled accounts</dt>
                    <dd class="text-text-strong"><?= (int) ($accounts['disabled'] ?? 0) ?></dd>
                    <dt class="text-text-muted">Stripe connected</dt>
                    <dd class="text-text-strong">
                        <?= (int) ($stripe['connected'] ?? 0) ?>
                        (<?= (int) ($stripe['charges_enabled'] ?? 0) ?> can charge)
                    </dd>
                </dl>
            </section>
        </div>

        <section class="mt-4 rounded-lg border border-card-border bg-card p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">
                Claude usage by workspace (30 days)
            </h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-card-border text-left text-xs uppercase tracking-wide text-text-muted">
                            <th class="pb-2 pr-4 font-medium">Workspace</th>
                            <th class="pb-2 pr-4 text-right font-medium">Calls</th>
                            <th class="pb-2 pr-4 text-right font-medium">Input</th>
                            <th class="pb-2 text-right font-medium">Output</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (($metrics['ai_spend'] ?? []) as $row): ?>
                        <tr class="border-b border-card-border">
                            <td class="py-2 pr-4">
                                <a href="/super-admin/organizations/<?= (int) $row['tenant_id'] ?>"
                                   class="text-brand hover:underline">
                                    <?= $e($row['tenant_name'] ?? ('#' . $row['tenant_id'])) ?>
                                </a>
                            </td>
                            <td class="py-2 pr-4 text-right text-text-strong"><?= (int) $row['calls'] ?></td>
                            <td class="py-2 pr-4 text-right text-text-muted"><?= number_format((int) $row['input_tokens']) ?></td>
                            <td class="py-2 text-right text-text-muted"><?= number_format((int) $row['output_tokens']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php require __DIR__ . '/_layout-end.php'; ?>
