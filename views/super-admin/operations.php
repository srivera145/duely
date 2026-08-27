<?php
/**
 * Tier one. The page opened first, so anything red is above the fold.
 *
 * Counts and error strings only — no message bodies, no client names, no
 * subjects. An operations screen is looked at constantly and half-read; it must
 * not be a place where customer content accumulates in front of somebody with no
 * reason to be reading it.
 */
$ops = $ops ?? ['sections' => [], 'alerts' => []];
$alerts = $ops['alerts'] ?? [];
$sections = $ops['sections'] ?? [];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$panelTitle = 'Operations';
$panelSubtitle = 'Checked ' . ($ops['checked_at'] ?? '');
require __DIR__ . '/_layout.php';
?>

        <?php if ($alerts === []): ?>
        <div class="mb-6 rounded-lg border border-success-border bg-success-soft px-4 py-3">
            <p class="text-sm font-semibold text-success-text">Nothing is broken.</p>
        </div>
        <?php else: ?>
        <!-- Red first, and nothing above it. -->
        <div class="mb-6 space-y-2">
            <?php foreach ($alerts as $alert): ?>
            <div class="rounded-lg border px-4 py-3 <?= $alert['level'] === 'critical'
                ? 'border-danger-border bg-danger-soft'
                : 'border-amber-500/30 bg-amber-500/10' ?>">
                <p class="text-sm font-semibold <?= $alert['level'] === 'critical' ? 'text-danger-text' : 'text-amber-400' ?>">
                    <?= $e($alert['label']) ?>
                </p>
                <?php if (!empty($alert['detail'])): ?>
                <p class="mt-1 text-sm text-text-muted"><?= $e($alert['detail']) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Worker and queue</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-text-muted">Last activity</dt>
                    <dd class="text-text-strong"><?= $e($sections['worker']['last_activity_at'] ?? 'never') ?></dd>
                    <dt class="text-text-muted">Seconds since</dt>
                    <dd class="text-text-strong"><?= $e($sections['worker']['seconds_since'] ?? '—') ?></dd>
                    <dt class="text-text-muted">Jobs waiting</dt>
                    <dd class="text-text-strong"><?= (int) ($sections['worker']['pending_jobs'] ?? 0) ?></dd>
                    <dt class="text-text-muted">Queue depth</dt>
                    <dd class="text-text-strong"><?= (int) ($sections['queue']['depth'] ?? 0) ?></dd>
                    <dt class="text-text-muted">Failed jobs (24h)</dt>
                    <dd class="text-text-strong"><?= (int) ($sections['queue']['failed_24h'] ?? 0) ?></dd>
                </dl>
            </section>

            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Chases and sends</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-text-muted">Overdue to send</dt>
                    <dd class="<?= (int) ($sections['chases']['stuck'] ?? 0) > 0
                        ? 'font-bold text-danger-text'
                        : 'text-text-strong' ?>"><?= (int) ($sections['chases']['stuck'] ?? 0) ?></dd>
                    <dt class="text-text-muted">Oldest due</dt>
                    <dd class="text-text-strong"><?= $e($sections['chases']['oldest_due'] ?? '—') ?></dd>
                    <dt class="text-text-muted">Failed sends (24h)</dt>
                    <dd class="text-text-strong"><?= (int) ($sections['sends']['total_24h'] ?? 0) ?></dd>
                </dl>
            </section>
        </div>

        <?php if (!empty($sections['sends']['grouped'])): ?>
        <section class="mt-4 rounded-lg border border-card-border bg-card p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">
                Send failures by reason (24h)
            </h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-card-border text-left text-xs uppercase tracking-wide text-text-muted">
                            <th class="pb-2 pr-4 font-medium">Reason</th>
                            <th class="pb-2 pr-4 font-medium">Status</th>
                            <th class="pb-2 pr-4 text-right font-medium">Count</th>
                            <th class="pb-2 text-right font-medium">Workspaces</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections['sends']['grouped'] as $row): ?>
                        <tr class="border-b border-card-border">
                            <td class="py-2 pr-4 font-mono text-xs text-text"><?= $e($row['reason']) ?></td>
                            <td class="py-2 pr-4 text-text-muted"><?= $e($row['status']) ?></td>
                            <td class="py-2 pr-4 text-right font-semibold text-text-strong"><?= (int) $row['total'] ?></td>
                            <td class="py-2 text-right text-text-muted"><?= (int) $row['tenants'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Mailboxes</h2>
                <p class="mt-2 text-sm text-text-muted">
                    <?= count($sections['mailboxes']['needs_reauth'] ?? []) ?> need reconnecting,
                    <?= count($sections['mailboxes']['stale_imap'] ?? []) ?> not polling
                </p>
                <?php foreach (array_slice($sections['mailboxes']['needs_reauth'] ?? [], 0, 10) as $row): ?>
                <div class="mt-2 border-t border-card-border pt-2 text-sm">
                    <a href="/super-admin/organizations/<?= (int) $row['tenant_id'] ?>"
                       class="text-brand hover:underline"><?= $e($row['tenant_name'] ?? ('#' . $row['tenant_id'])) ?></a>
                    <span class="ml-2 font-mono text-xs text-text-muted"><?= $e($row['last_error'] ?? '') ?></span>
                </div>
                <?php endforeach; ?>
            </section>

            <section class="rounded-lg border border-card-border bg-card p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">Stripe events and AI</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <?php foreach (($sections['webhooks']['subscription'] ?? []) as $row): ?>
                    <dt class="text-text-muted">Subscription <?= $e($row['status']) ?></dt>
                    <dd class="text-text-strong"><?= (int) $row['total'] ?></dd>
                    <?php endforeach; ?>
                    <?php foreach (($sections['webhooks']['connect'] ?? []) as $row): ?>
                    <dt class="text-text-muted">Connect <?= $e($row['status']) ?></dt>
                    <dd class="text-text-strong"><?= (int) $row['total'] ?></dd>
                    <?php endforeach; ?>
                    <dt class="text-text-muted">AI calls (24h)</dt>
                    <dd class="text-text-strong"><?= (int) ($sections['ai']['totals']['calls'] ?? 0) ?></dd>
                    <dt class="text-text-muted">Input tokens</dt>
                    <dd class="text-text-strong"><?= number_format((int) ($sections['ai']['totals']['input'] ?? 0)) ?></dd>
                    <dt class="text-text-muted">Output tokens</dt>
                    <dd class="text-text-strong"><?= number_format((int) ($sections['ai']['totals']['output'] ?? 0)) ?></dd>
                    <dt class="text-text-muted">Budget exhausted</dt>
                    <dd class="text-text-strong">
                        <?= (int) ($sections['ai']['budget_exhausted_tenants'] ?? 0) ?> workspaces
                    </dd>
                </dl>
            </section>
        </div>
<?php require __DIR__ . '/_layout-end.php'; ?>
