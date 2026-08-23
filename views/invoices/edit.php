<?php
/**
 * Duely — invoice editor. Handles both new and existing invoices.
 */
$invoice = $invoice ?? null;
$clients = $clients ?? [];
$chase = $chase ?? null;
$isNew = $invoice === null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$amountValue = '';
if (!$isNew) {
    // Render stored cents back as a plain decimal without ever using a float.
    $cents = (int) $invoice['amount_cents'];
    $amountValue = intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

$daysOverdue = $isNew ? null : \Keel\App\Models\Invoice::daysOverdue($invoice);
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
                <p class="text-sm text-text-muted">Invoices</p>
                <h1 class="text-3xl font-bold text-text-strong">
                    <?= $isNew ? 'New invoice' : $e($invoice['number']) ?>
                </h1>
                <?php if (!$isNew && $invoice['status'] === 'open' && $daysOverdue > 0): ?>
                <p class="mt-2 text-sm text-amber-400"><?= (int) $daysOverdue ?> days overdue</p>
                <?php endif; ?>
            </div>
            <a href="/invoices" class="text-sm text-text-muted hover:text-text-strong">Back to invoices</a>
        </div>

        <?php if (!$isNew && $chase !== null): ?>
        <div class="mb-6 rounded-xl border border-card-border bg-card p-4 text-sm">
            <span class="text-text-muted">Chase status:</span>
            <span class="font-medium text-text"><?= $e(ucfirst((string) $chase['status'])) ?></span>
            <?php if (!empty($chase['paused_reason'])): ?>
            <span class="text-text-muted">— <?= $e(str_replace('_', ' ', (string) $chase['paused_reason'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($chase['next_send_at'])): ?>
            <span class="text-text-muted">· next reminder <?= $e($chase['next_send_at']) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form id="invoice-form" class="space-y-6" novalidate>
            <input type="hidden" name="id" value="<?= $isNew ? '' : (int) $invoice['id'] ?>">

            <section class="rounded-xl border border-card-border bg-card p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="number" class="block text-sm font-medium text-text">Invoice number</label>
                        <input type="text" id="number" name="number" required
                               value="<?= $isNew ? '' : $e($invoice['number']) ?>"
                               placeholder="INV-1001" class="form-input mt-1 w-full font-mono">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="number"></p>
                    </div>
                    <div>
                        <label for="client_id" class="block text-sm font-medium text-text">Client</label>
                        <select id="client_id" name="client_id" required class="form-input mt-1 w-full">
                            <option value="">Choose a client…</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['id'] ?>"
                                <?= !$isNew && (int) $invoice['client_id'] === (int) $client['id'] ? 'selected' : '' ?>>
                                <?= $e($client['name']) ?> — <?= $e($client['email']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-text-muted" data-error-for="client_id">
                            <a href="/clients/new" class="hover:underline">Add a new client</a>
                        </p>
                    </div>
                    <div>
                        <label for="amount" class="block text-sm font-medium text-text">Amount</label>
                        <input type="text" id="amount" name="amount" required
                               value="<?= $e($amountValue) ?>"
                               placeholder="3,200.00" class="form-input mt-1 w-full font-mono">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="amount">
                            Symbols and commas are fine.
                        </p>
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-medium text-text">Currency</label>
                        <input type="text" id="currency" name="currency" maxlength="3"
                               value="<?= $isNew ? 'USD' : $e($invoice['currency']) ?>"
                               class="form-input mt-1 w-full font-mono uppercase">
                    </div>
                    <div>
                        <label for="issue_date" class="block text-sm font-medium text-text">Issue date</label>
                        <input type="text" id="issue_date" name="issue_date"
                               value="<?= $isNew ? '' : $e($invoice['issue_date']) ?>"
                               placeholder="2026-08-01" class="form-input mt-1 w-full font-mono">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="issue_date"></p>
                    </div>
                    <div>
                        <label for="due_date" class="block text-sm font-medium text-text">Due date</label>
                        <input type="text" id="due_date" name="due_date" required
                               value="<?= $isNew ? '' : $e($invoice['due_date']) ?>"
                               placeholder="2026-08-31" class="form-input mt-1 w-full font-mono">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="due_date">
                            Reminders are timed from this date.
                        </p>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-text">Status</label>
                        <select id="status" name="status" class="form-input mt-1 w-full">
                            <?php foreach (['open' => 'Open', 'paid' => 'Paid', 'void' => 'Void'] as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= !$isNew && $invoice['status'] === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-text-muted">Marking an invoice paid stops its chase.</p>
                    </div>
                    <div>
                        <label for="payment_url" class="block text-sm font-medium text-text">Payment link</label>
                        <input type="url" id="payment_url" name="payment_url"
                               value="<?= $isNew ? '' : $e($invoice['payment_url']) ?>"
                               placeholder="https://…" class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="payment_url">
                            Included in reminders when set.
                        </p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="notes" class="block text-sm font-medium text-text">Notes</label>
                        <textarea id="notes" name="notes" rows="3" class="form-input mt-1 w-full"><?= $isNew ? '' : $e($invoice['notes']) ?></textarea>
                        <p class="mt-1 text-xs text-text-muted">Private to you — never sent to the client.</p>
                    </div>
                </div>
            </section>

            <div id="invoice-error" class="hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
            <div id="invoice-saved" class="hidden rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-400"></div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create invoice' : 'Save changes' ?></button>
                <a href="/invoices" class="btn btn-secondary border border-card-border">Cancel</a>
                <?php if (!$isNew): ?>
                <button type="button" id="delete-invoice" class="btn ml-auto text-sm text-danger-text hover:underline">
                    Delete invoice
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</body>
</html>
