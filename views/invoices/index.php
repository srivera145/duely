<?php
/**
 * Duely — invoice list.
 *
 * Rows render server-side so the page is useful without JavaScript; the filter
 * bar then swaps the table body via the JSON endpoint for the interactive case.
 */
$invoices = $invoices ?? [];
$tallies = $tallies ?? ['all' => 0, 'open' => 0, 'overdue' => 0, 'paid' => 0, 'void' => 0];
$outstanding = $outstanding ?? [];
$filters = $filters ?? ['status' => 'all', 'client_id' => null, 'search' => '', 'sort' => 'days_overdue'];
$clients = $clients ?? [];
$page = $page ?? 1;
$total = $total ?? 0;
$perPage = $perPage ?? 50;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents, string $currency): string => \Keel\App\Services\MoneyParser::format($cents, $currency);

$statusTabs = [
    'all' => 'All',
    'open' => 'Open',
    'overdue' => 'Overdue',
    'paid' => 'Paid',
    'void' => 'Void',
];

$sortOptions = [
    'days_overdue' => 'Most overdue first',
    'days_overdue_asc' => 'Least overdue first',
    'due_date' => 'Due date, soonest',
    'due_date_desc' => 'Due date, latest',
    'amount' => 'Largest amount',
    'amount_asc' => 'Smallest amount',
    'client' => 'Client name',
    'number' => 'Invoice number',
    'newest' => 'Recently added',
];

/** Describe a chase in a few words, or say there is none. */
$chaseLabel = static function (array $row): array {
    if (empty($row['chase_id'])) {
        return ['text' => 'Not chasing', 'class' => 'text-text-muted'];
    }

    $reasons = [
        'client_replied' => 'Paused — client replied',
        'invoice_paid' => 'Paused — marked paid',
        'bounced' => 'Paused — email bounced',
        'needs_reauth' => 'Paused — mailbox needs reconnecting',
        'manual' => 'Paused',
    ];

    return match ($row['chase_status']) {
        'scheduled' => ['text' => 'Scheduled', 'class' => 'text-text'],
        'active' => ['text' => 'Chasing · step ' . (int) $row['chase_position'], 'class' => 'text-emerald-400'],
        'paused' => ['text' => $reasons[$row['chase_paused_reason']] ?? 'Paused', 'class' => 'text-amber-400'],
        'completed' => ['text' => 'Sequence finished', 'class' => 'text-text-muted'],
        'stopped' => ['text' => 'Stopped', 'class' => 'text-text-muted'],
        default => ['text' => 'Not chasing', 'class' => 'text-text-muted'],
    };
};

$queryWith = static function (array $overrides) use ($filters): string {
    $query = array_filter([
        'status' => $overrides['status'] ?? $filters['status'],
        'sort' => $overrides['sort'] ?? $filters['sort'],
        'search' => $overrides['search'] ?? $filters['search'],
        'client_id' => $overrides['client_id'] ?? $filters['client_id'],
    ], static fn ($v): bool => $v !== null && $v !== '' && $v !== 'all');

    return $query === [] ? '' : '?' . http_build_query($query);
};
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-7xl px-4 py-10">

        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-text-muted">Duely</p>
                <h1 class="text-3xl font-bold text-text-strong">Invoices</h1>
                <?php if ($outstanding !== []): ?>
                <p class="mt-2 text-sm text-text-muted">
                    Outstanding:
                    <?php foreach ($outstanding as $currency => $cents): ?>
                    <span class="font-semibold text-text"><?= $e($money((int) $cents, (string) $currency)) ?></span>
                    <?php endforeach; ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="/invoices/import" class="btn btn-secondary border border-card-border">Import CSV</a>
                <a href="/invoices/new" class="btn btn-primary">New invoice</a>
            </div>
        </div>

        <!-- Status tabs -->
        <div class="mb-4 flex flex-wrap gap-1 border-b border-card-border" role="tablist">
            <?php foreach ($statusTabs as $key => $label): ?>
            <a href="/invoices<?= $e($queryWith(['status' => $key])) ?>"
               data-status-tab="<?= $e($key) ?>"
               class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition <?= $filters['status'] === $key
                   ? 'border-brand text-text-strong'
                   : 'border-transparent text-text-muted hover:text-text' ?>">
                <?= $e($label) ?>
                <span class="ml-1 text-xs text-text-muted"><?= (int) ($tallies[$key] ?? 0) ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Filter bar -->
        <form id="invoice-filters" method="get" action="/invoices" class="mb-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="status" value="<?= $e($filters['status']) ?>">
            <div class="min-w-[220px] flex-1">
                <label for="search" class="block text-xs font-medium text-text-muted">Search</label>
                <input type="search" id="search" name="search" value="<?= $e($filters['search']) ?>"
                       placeholder="Invoice number, client, or company"
                       class="form-input mt-1 w-full">
            </div>
            <div>
                <label for="client_id" class="block text-xs font-medium text-text-muted">Client</label>
                <select id="client_id" name="client_id" class="form-input mt-1">
                    <option value="">All clients</option>
                    <?php foreach ($clients as $client): ?>
                    <option value="<?= (int) $client['id'] ?>" <?= (int) $filters['client_id'] === (int) $client['id'] ? 'selected' : '' ?>>
                        <?= $e($client['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="sort" class="block text-xs font-medium text-text-muted">Sort by</label>
                <select id="sort" name="sort" class="form-input mt-1">
                    <?php foreach ($sortOptions as $key => $label): ?>
                    <option value="<?= $e($key) ?>" <?= $filters['sort'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary border border-card-border">Apply</button>
        </form>

        <?php if ($invoices === []): ?>
        <div class="rounded-xl border border-card-border bg-card p-10">
            <div class="empty-state">
                <p class="empty-state-title">
                    <?= $filters['search'] !== '' || $filters['status'] !== 'all'
                        ? 'No invoices match these filters'
                        : 'No invoices yet' ?>
                </p>
                <p class="empty-state-text">
                    <?= $filters['search'] !== '' || $filters['status'] !== 'all'
                        ? 'Try clearing the search or switching tabs.'
                        : 'Import your spreadsheet to get started — it takes about a minute.' ?>
                </p>
                <?php if ($filters['search'] === '' && $filters['status'] === 'all'): ?>
                <a href="/invoices/import" class="btn btn-primary mt-4">Import a CSV</a>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>

        <div class="overflow-x-auto rounded-xl border border-card-border">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Client</th>
                        <th class="text-right">Amount</th>
                        <th>Due</th>
                        <th class="text-right">Overdue</th>
                        <th>Status</th>
                        <th>Chase</th>
                    </tr>
                </thead>
                <tbody id="invoice-rows">
                    <?php foreach ($invoices as $row): ?>
                    <?php
                    $days = (int) $row['days_overdue'];
                    $isOverdue = $row['status'] === 'open' && $days > 0;
                    $chase = $chaseLabel($row);
                    ?>
                    <tr class="hover:bg-table-row-hover">
                        <td>
                            <a href="/invoices/<?= (int) $row['id'] ?>" class="font-medium text-text-strong hover:underline">
                                <?= $e($row['number']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="text-text"><?= $e($row['client_name']) ?></div>
                            <?php if (!empty($row['client_company'])): ?>
                            <div class="text-xs text-text-muted"><?= $e($row['client_company']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right font-mono text-text">
                            <?= $e($money((int) $row['amount_cents'], (string) $row['currency'])) ?>
                        </td>
                        <td class="whitespace-nowrap text-text-muted"><?= $e($row['due_date']) ?></td>
                        <td class="text-right whitespace-nowrap">
                            <?php if ($row['status'] !== 'open'): ?>
                            <span class="text-text-muted">—</span>
                            <?php elseif ($days > 0): ?>
                            <span class="font-semibold <?= $days >= 30 ? 'text-red-400' : 'text-amber-400' ?>">
                                <?= $days ?> day<?= $days === 1 ? '' : 's' ?>
                            </span>
                            <?php elseif ($days === 0): ?>
                            <span class="text-amber-400">Due today</span>
                            <?php else: ?>
                            <span class="text-text-muted">in <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusClass = match ($row['status']) {
                                'paid' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400',
                                'void' => 'border-gray-500/30 bg-gray-500/10 text-gray-400',
                                default => $isOverdue
                                    ? 'border-amber-500/30 bg-amber-500/10 text-amber-400'
                                    : 'border-card-border bg-surface-muted text-text-muted',
                            };
                            ?>
                            <span class="rounded-full border px-2 py-0.5 text-xs font-medium <?= $statusClass ?>">
                                <?= $e(ucfirst((string) $row['status'])) ?>
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-sm <?= $e($chase['class']) ?>"><?= $e($chase['text']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $pages = (int) ceil($total / max(1, $perPage));
        if ($pages > 1):
        ?>
        <div class="mt-4 flex items-center justify-between text-sm text-text-muted">
            <span>
                Showing <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $total) ?> of <?= $total ?>
            </span>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                <a href="/invoices<?= $e($queryWith([])) ?><?= $queryWith([]) === '' ? '?' : '&' ?>page=<?= $page - 1 ?>"
                   class="btn btn-sm btn-secondary border border-card-border">Previous</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                <a href="/invoices<?= $e($queryWith([])) ?><?= $queryWith([]) === '' ? '?' : '&' ?>page=<?= $page + 1 ?>"
                   class="btn btn-sm btn-secondary border border-card-border">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>
