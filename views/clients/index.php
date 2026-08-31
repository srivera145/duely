<?php
/**
 * Duely — client list, with each client's outstanding balance.
 */
$clients = $clients ?? [];
$search = $search ?? '';
$total = $total ?? 0;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
<?php require __DIR__ . '/../partials/nav-bar.php'; ?>
    <div class="mx-auto max-w-6xl px-4 py-10">

        <?php
        ob_start(); ?>
                <p class="mt-2 text-sm text-text-muted"><?= (int) $total ?> in total</p>
        <?php $pageSubtitle = ob_get_clean();
        ob_start(); ?>
            <div class="flex flex-wrap gap-2">
                <a href="/invoices/import" class="btn btn-secondary border border-card-border">Import CSV</a>
                <a href="/clients/new" class="btn btn-primary">New client</a>
            </div>
        <?php $pageActions = ob_get_clean();
        $pageTitle = 'Clients';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <?php if (($notice ?? null) !== null): ?>
        <div class="mb-4 rounded-xl border border-success-border bg-success-soft p-4">
            <p class="text-sm text-success-text"><?= $e($notice) ?></p>
        </div>
        <?php endif; ?>

        <?php if ((int) ($clientsOnDefault ?? 0) > 0): ?>
        <!--
            Surfaced, not silently corrected. These clients predate the workspace
            having a timezone, so their reminders are timed against UTC. That is
            probably wrong -- but moving somebody's client to a new zone moves
            every reminder scheduled for them by hours, so it is offered, once,
            with the count visible.
        -->
        <div class="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-amber-400">
                        <?= (int) $clientsOnDefault ?>
                        <?= (int) $clientsOnDefault === 1 ? 'client is' : 'clients are' ?> still set to UTC
                    </p>
                    <p class="mt-1 text-sm text-text-muted">
                        Their reminders are timed against UTC rather than
                        <?= $e($workspaceTimezone) ?>. Set them individually below, or move them all
                        at once.
                    </p>
                </div>
                <form method="post" action="/clients/timezone-backfill" class="shrink-0">
                    <?= \Keel\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-primary btn-sm">
                        Set all to <?= $e($workspaceTimezone) ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <form method="get" action="/clients" class="mb-4 flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label for="search" class="block text-xs font-medium text-text-muted">Search</label>
                <input type="search" id="search" name="search" value="<?= $e($search) ?>"
                       placeholder="Name, email, or company" class="form-input mt-1 w-full">
            </div>
            <button type="submit" class="btn btn-secondary border border-card-border">Search</button>
            <?php if ($search !== ''): ?>
            <a href="/clients" class="text-sm text-text-muted hover:underline">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($clients === []): ?>
        <div class="rounded-xl border border-card-border bg-card p-10">
            <div class="empty-state">
                <p class="empty-state-title">
                    <?= $search !== '' ? 'No clients match that search' : 'No clients yet' ?>
                </p>
                <p class="empty-state-text">
                    <?= $search !== ''
                        ? 'Try a different name or email.'
                        : 'Clients are created automatically when you import invoices, or you can add one by hand.' ?>
                </p>
                <?php if ($search === ''): ?>
                <a href="/invoices/import" class="btn btn-primary mt-4">Import a CSV</a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>

        <div class="overflow-x-auto rounded-xl border border-card-border">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Timezone</th>
                        <th class="text-right">Open invoices</th>
                        <th class="text-right">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                    <tr class="hover:bg-table-row-hover">
                        <td>
                            <a href="/clients/<?= (int) $client['id'] ?>" class="font-medium text-text-strong hover:underline">
                                <?= $e($client['name']) ?>
                            </a>
                            <?php if ($client['is_archived']): ?>
                            <span class="ml-2 rounded-full border border-card-border px-2 py-0.5 text-xs text-text-muted">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-text-muted"><?= $e($client['email']) ?></td>
                        <td class="text-text-muted"><?= $e($client['company']) ?></td>
                        <td>
                            <?php
                            // Flagged, not fixed. A client still on UTC in a
                            // workspace that is not is probably wrong -- their
                            // reminders are timed against the wrong day -- but
                            // some clients genuinely are elsewhere, so this
                            // points at it rather than rewriting it.
                            $stale = ($workspaceTimezone ?? 'UTC') !== 'UTC'
                                && ($client['timezone'] ?? 'UTC') === 'UTC';
                            ?>
                            <?php if ($stale): ?>
                            <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-400"
                                  title="Reminders for this client are timed against UTC, not <?= $e($workspaceTimezone) ?>.">
                                UTC
                            </span>
                            <?php else: ?>
                            <span class="text-xs text-text-muted"><?= $e($client['timezone'] ?? 'UTC') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-text-muted"><?= (int) $client['open_invoice_count'] ?></td>
                        <td class="text-right font-mono <?= $client['outstanding_cents'] > 0 ? 'text-text' : 'text-text-muted' ?>">
                            <?= $e($client['outstanding_formatted']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
