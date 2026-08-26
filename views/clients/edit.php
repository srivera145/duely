<?php
/**
 * Duely — client editor, with their invoice history alongside.
 */
$client = $client ?? null;
$invoices = $invoices ?? [];
$isNew = $client === null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents, string $currency): string => \Keel\App\Services\MoneyParser::format($cents, $currency);
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-3xl px-4 py-10">

        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-text-muted">Clients</p>
                <h1 class="text-3xl font-bold text-text-strong">
                    <?= $isNew ? 'New client' : $e($client['name']) ?>
                </h1>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="/clients" class="text-sm text-text-muted hover:text-text-strong">Back to clients</a>
                <?php require __DIR__ . '/../partials/sign-out.php'; ?>
            </div>
        </div>

        <form id="client-form" class="space-y-6" novalidate>
            <input type="hidden" name="id" value="<?= $isNew ? '' : (int) $client['id'] ?>">

            <section class="rounded-xl border border-card-border bg-card p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-medium text-text">Name</label>
                        <input type="text" id="name" name="name" required
                               value="<?= $isNew ? '' : $e($client['name']) ?>"
                               class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="name"></p>
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-text">Email</label>
                        <input type="email" id="email" name="email" required
                               value="<?= $isNew ? '' : $e($client['email']) ?>"
                               class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="email">
                            Reminders go here. Also how imports match this client.
                        </p>
                    </div>
                    <div>
                        <label for="company" class="block text-sm font-medium text-text">Company</label>
                        <input type="text" id="company" name="company"
                               value="<?= $isNew ? '' : $e($client['company']) ?>"
                               class="form-input mt-1 w-full">
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-medium text-text">Phone</label>
                        <input type="text" id="phone" name="phone"
                               value="<?= $isNew ? '' : $e($client['phone']) ?>"
                               class="form-input mt-1 w-full">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="notes" class="block text-sm font-medium text-text">Notes</label>
                        <textarea id="notes" name="notes" rows="3" class="form-input mt-1 w-full"><?= $isNew ? '' : $e($client['notes']) ?></textarea>
                        <p class="mt-1 text-xs text-text-muted">Private to you — never sent to the client.</p>
                    </div>
                </div>
            </section>

            <div id="client-error" class="hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
            <div id="client-saved" class="hidden rounded-lg border border-success-border bg-success-soft p-3 text-sm text-success-text"></div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create client' : 'Save changes' ?></button>
                <a href="/clients" class="btn btn-secondary border border-card-border">Cancel</a>
                <?php if (!$isNew): ?>
                <button type="button" id="delete-client" class="btn ml-auto text-sm text-danger-text hover:underline">
                    Delete client
                </button>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!$isNew && $invoices !== []): ?>
        <section class="mt-10">
            <h2 class="text-lg font-semibold text-text-strong">Invoices</h2>
            <div class="mt-4 overflow-x-auto rounded-xl border border-card-border">
                <table class="table">
                    <thead>
                        <tr><th>Invoice</th><th class="text-right">Amount</th><th>Due</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                        <tr class="hover:bg-table-row-hover">
                            <td>
                                <a href="/invoices/<?= (int) $invoice['id'] ?>" class="text-text-strong hover:underline">
                                    <?= $e($invoice['number']) ?>
                                </a>
                            </td>
                            <td class="text-right font-mono text-text">
                                <?= $e($money((int) $invoice['amount_cents'], (string) $invoice['currency'])) ?>
                            </td>
                            <td class="text-text-muted"><?= $e($invoice['due_date']) ?></td>
                            <td class="text-text-muted"><?= $e(ucfirst((string) $invoice['status'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </div>
</body>
</html>
