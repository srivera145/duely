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
