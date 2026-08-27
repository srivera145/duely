<?php
/**
 * The trail.
 *
 * Read-only, and there is deliberately no control on this page to remove a row.
 * The table has no delete or update path anywhere in the codebase: the operator
 * being audited is the same person who deploys the code, so the protection has
 * to be that the vocabulary does not exist rather than that the button is
 * hidden.
 */
$entries = $entries ?? [];
$filterTenantId = $filterTenantId ?? null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$panelTitle = 'Audit';
$panelSubtitle = 'Append-only. Nothing here can be edited or deleted.';
require __DIR__ . '/_layout.php';
?>

        <?php if ($filterTenantId !== null): ?>
        <p class="mb-3 text-sm text-text-muted">
            Filtered to workspace #<?= (int) $filterTenantId ?>.
            <a href="/super-admin/audit" class="text-brand hover:underline">Show all</a>
        </p>
        <?php endif; ?>

        <section class="rounded-lg border border-card-border bg-card p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-card-border text-left text-xs uppercase tracking-wide text-text-muted">
                            <th class="p-3 font-medium">When</th>
                            <th class="p-3 font-medium">Who</th>
                            <th class="p-3 font-medium">Kind</th>
                            <th class="p-3 font-medium">Action</th>
                            <th class="p-3 font-medium">Workspace</th>
                            <th class="p-3 font-medium">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                        <tr class="border-b border-card-border">
                            <td class="p-3 font-mono text-xs text-text-muted"><?= $e($entry['created_at']) ?></td>
                            <td class="p-3 text-text-muted"><?= $e($entry['super_admin_email']) ?></td>
                            <td class="p-3">
                                <span class="rounded px-1.5 py-0.5 text-xs font-semibold <?= match ($entry['kind']) {
                                    'impersonation' => 'bg-danger-soft text-danger-text',
                                    'action' => 'bg-amber-500/10 text-amber-400',
                                    default => 'bg-surface-muted text-text-muted',
                                } ?>"><?= $e($entry['kind']) ?></span>
                            </td>
                            <td class="p-3 font-mono text-xs text-text"><?= $e($entry['action']) ?></td>
                            <td class="p-3 text-text-muted">
                                <?php if ($entry['tenant_id'] !== null): ?>
                                <a href="/super-admin/audit?tenant_id=<?= (int) $entry['tenant_id'] ?>"
                                   class="text-brand hover:underline">#<?= (int) $entry['tenant_id'] ?></a>
                                <?php else: ?>
                                &mdash;
                                <?php endif; ?>
                            </td>
                            <td class="p-3 text-text-muted"><?= $e($entry['reason'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($entries === []): ?>
                        <tr><td colspan="6" class="p-6 text-center text-sm text-text-muted">Nothing recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php require __DIR__ . '/_layout-end.php'; ?>
