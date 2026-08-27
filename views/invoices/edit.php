<?php
/**
 * Duely — invoice editor. Handles both new and existing invoices.
 */
$invoice = $invoice ?? null;
$clients = $clients ?? [];
$chase = $chase ?? null;
$isNew = $invoice === null;

// Fields read off an uploaded document. Suggestions, nothing more: the form is
// the review step, and the user is the one who decides they are right.
$draft = $draft ?? ['values' => [], 'confidence' => null, 'notes' => null, 'warnings' => []];
$suggested = static fn (string $field): string => (string) ($draft['values'][$field] ?? '');

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

        <?php
        ob_start(); ?>
                <?php if (!$isNew && $invoice['status'] === 'open' && $daysOverdue > 0): ?>
                <p class="mt-2 text-sm <?= \Keel\App\Services\ToneRamp::text(\Keel\App\Services\ToneRamp::forDaysOverdue((int) $daysOverdue)) ?>"><?= (int) $daysOverdue ?> days overdue</p>
                <?php endif; ?>
        <?php $pageSubtitle = ob_get_clean();
        ob_start(); ?>
            <?php if ($isNew): ?>
            <a href="/invoices" class="text-sm text-text-muted hover:text-text-strong">Back to invoices</a>
            <?php else: ?>
            <a href="/invoices/<?= (int) $invoice['id'] ?>" class="text-sm text-text-muted hover:text-text-strong">
                Back to <?= $e($invoice['number']) ?>
            </a>
            <?php endif; ?>
        <?php $pageActions = ob_get_clean();
        $pageTitle = $isNew ? 'New invoice' : $e($invoice['number']);
        $pageEyebrow = 'Invoices';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

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

        <?php if ($isNew && $draft['values'] !== []): ?>
        <div class="mb-6 rounded-xl border <?= $draft['warnings'] === [] ? 'border-brand bg-brand/5' : 'border-tone-firm-border bg-tone-firm-soft' ?> p-5">
            <p class="font-semibold text-text-strong">
                Read from your document<?= $draft['confidence'] !== null ? ' — ' . $e($draft['confidence']) . ' confidence' : '' ?>
            </p>
            <p class="mt-1 text-sm text-text-muted">
                Nothing is saved yet. Check every field, then save.
            </p>
            <?php if ($draft['notes'] !== null): ?>
            <p class="mt-2 text-sm text-text-muted"><?= $e($draft['notes']) ?></p>
            <?php endif; ?>
            <?php if ($draft['warnings'] !== []): ?>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-tone-firm">
                <?php foreach ($draft['warnings'] as $warning): ?>
                <li><?= $e($warning) ?></li>
                <?php endforeach; ?>
            </ul>
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
                               value="<?= $isNew ? $e($suggested('number')) : $e($invoice['number']) ?>"
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
                               value="<?= $isNew ? $e($suggested('amount')) : $e($amountValue) ?>"
                               placeholder="3,200.00" class="form-input mt-1 w-full font-mono">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="amount">
                            Symbols and commas are fine.
                        </p>
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-medium text-text">Currency</label>
                        <input type="text" id="currency" name="currency" maxlength="3"
                               value="<?= $isNew ? $e($suggested('currency') ?: 'USD') : $e($invoice['currency']) ?>"
                               class="form-input mt-1 w-full font-mono uppercase">
                    </div>
                    <div>
                        <label for="issue_date" class="block text-sm font-medium text-text">Issue date</label>
                        <input type="text" id="issue_date" name="issue_date"
                               value="<?= $isNew ? $e($suggested('issue_date')) : $e($invoice['issue_date']) ?>"
                               placeholder="2026-08-01" class="form-input mt-1 w-full font-mono">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="issue_date"></p>
                    </div>
                    <div>
                        <label for="due_date" class="block text-sm font-medium text-text">Due date</label>
                        <input type="text" id="due_date" name="due_date" required
                               value="<?= $isNew ? $e($suggested('due_date')) : $e($invoice['due_date']) ?>"
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
                    <div class="sm:col-span-2">
                        <?php
                        // Four states in one control. The fourth -- "use a link
                        // I paste" -- is selected by typing in the field below
                        // rather than by a radio: making somebody set both would
                        // be asking the same question twice, and the resolution
                        // order already treats a pasted link as the answer.
                        $manualUrl = !$isNew
                            && !empty($invoice['payment_url'])
                            && empty($invoice['payment_url_is_generated']);
                        $linkMode = $isNew ? null : ($invoice['payment_link_mode'] ?? null);
                        $workspaceMode = $workspacePaymentMode ?? 'always';

                        $followsLabel = match ($workspaceMode) {
                            'never' => 'no pay button',
                            'manual_only' => 'only links you add yourself',
                            default => 'add a pay button',
                        };
                        ?>
                        <fieldset<?= $manualUrl ? ' disabled' : '' ?>>
                            <legend class="block text-sm font-medium text-text">Pay button on reminders</legend>

                            <div class="mt-2 space-y-2">
                                <label class="flex cursor-pointer items-start gap-2 text-sm">
                                    <input type="radio" name="payment_link_mode" value="default"
                                           class="mt-0.5 shrink-0 accent-brand"
                                           <?= $linkMode === null || $linkMode === 'default' ? 'checked' : '' ?>>
                                    <span class="text-text-muted">
                                        Follow the workspace default
                                        <span class="text-text-muted">(<?= $e($followsLabel) ?>)</span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-2 text-sm">
                                    <input type="radio" name="payment_link_mode" value="generate"
                                           class="mt-0.5 shrink-0 accent-brand"
                                           <?= $linkMode === 'generate' ? 'checked' : '' ?>>
                                    <span class="text-text-muted">Add a Duely pay button to this invoice</span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-2 text-sm">
                                    <input type="radio" name="payment_link_mode" value="none"
                                           class="mt-0.5 shrink-0 accent-brand"
                                           <?= $linkMode === 'none' ? 'checked' : '' ?>>
                                    <span class="text-text-muted">
                                        No pay button on this invoice
                                        <span class="block text-xs">For a client paying by transfer, or an invoice already part paid.</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <?php if ($manualUrl): ?>
                        <p class="mt-2 text-xs text-text-muted">
                            You have pasted your own payment link below, so that is what goes out. Clear the
                            field to choose one of these instead.
                        </p>
                        <?php endif; ?>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="payment_url" class="block text-sm font-medium text-text">Payment link</label>
                        <input type="url" id="payment_url" name="payment_url"
                               value="<?= $isNew ? '' : $e($invoice['payment_url']) ?>"
                               placeholder="https://…" class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="payment_url">
                            <?php if (!$isNew && !empty($invoice['payment_url_is_generated'])): ?>
                            Duely made this one through Stripe. Type your own here and Duely will use yours instead.
                            <?php else: ?>
                            Paste your own &mdash; PayPal, a bank page, anything &mdash; and Duely uses it
                            instead of generating one. It overrides every setting above.
                            <?php endif; ?>
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
            <div id="invoice-saved" class="hidden rounded-lg border border-success-border bg-success-soft p-3 text-sm text-success-text"></div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create invoice' : 'Save changes' ?></button>
                <!--
                    Cancel returns where the user came from. On an existing
                    invoice that is the invoice, not the list -- dropping them
                    back at the top of a list they had already navigated out of
                    is the same as losing their place.
                -->
                <a href="<?= $isNew ? '/invoices' : '/invoices/' . (int) $invoice['id'] ?>"
                   class="btn btn-secondary border border-card-border">Cancel</a>
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
