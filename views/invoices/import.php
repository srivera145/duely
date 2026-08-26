<?php
/**
 * Duely — CSV import wizard.
 *
 * Five steps, each one explicit: upload, preview, map, validate, confirm.
 * Nothing is written until the confirm button on the last step is pressed, and
 * the screen says so at every stage.
 */
$fields = $fields ?? [];
$extractionConfigured = $extractionConfigured ?? false;
$extractionEnabled = $extractionEnabled ?? false;
$previewRows = $previewRows ?? 10;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$steps = [
    1 => 'Upload',
    2 => 'Check the columns',
    3 => 'Review',
    4 => 'Done',
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-5xl px-4 py-10">

        <?php
        ob_start(); ?>
                <p class="mt-2 max-w-2xl text-sm text-text-muted">
                    Export your sheet as CSV and drop it in. Duely reads the common column names on its own,
                    copes with mixed date and currency formats, and shows you exactly what will happen before
                    anything is saved.
                </p>
        <?php $pageSubtitle = ob_get_clean();
        ob_start(); ?>
            <a href="/invoices" class="text-sm text-text-muted hover:text-text-strong">Back to invoices</a>
        <?php $pageActions = ob_get_clean();
        $pageTitle = 'Import from a spreadsheet';
        $pageEyebrow = 'Invoices';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <!-- Step indicator -->
        <ol class="mb-8 flex flex-wrap items-center gap-2 text-sm" id="import-steps">
            <?php foreach ($steps as $number => $label): ?>
            <li class="flex items-center gap-2" data-step-indicator="<?= $number ?>">
                <span class="flex h-6 w-6 items-center justify-center rounded-full border border-card-border text-xs text-text-muted"
                      data-step-number><?= $number ?></span>
                <span class="text-text-muted" data-step-label><?= $e($label) ?></span>
                <?php if ($number < count($steps)): ?>
                <span class="mx-2 text-text-muted" aria-hidden="true">→</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>

        <!-- Step 1: upload -->
        <section data-step-panel="1" class="rounded-xl border border-card-border bg-card p-6">
            <h2 class="text-lg font-semibold text-text-strong">Choose your CSV</h2>
            <p class="mt-1 text-sm text-text-muted">
                Uploading only reads the file. Nothing is imported until you confirm on the last step.
            </p>

            <div id="drop-zone"
                 class="mt-5 cursor-pointer rounded-xl border-2 border-dashed border-card-border p-10 text-center transition hover:border-brand">
                <input type="file" id="csv-file" accept=".csv,.txt,.tsv,text/csv" class="hidden">
                <p class="text-text">Drop a CSV here, or <span class="font-semibold text-brand">browse</span></p>
                <p class="mt-1 text-xs text-text-muted">Up to 8MB. Exported from Excel, Numbers, or Google Sheets.</p>
            </div>

            <div id="upload-error" class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>

            <?php if ($extractionConfigured): ?>
            <!-- The other way in: the invoice document itself. -->
            <div class="mt-8 border-t border-card-border pt-6">
                <h3 class="font-semibold text-text-strong">Only have the invoice itself?</h3>
                <p class="mt-1 text-sm text-text-muted">
                    Drop the PDF or a photo and Duely reads the details off it. You check every
                    field before anything is saved.
                </p>

                <?php if ($extractionEnabled): ?>
                <div id="doc-zone"
                     class="mt-4 cursor-pointer rounded-xl border-2 border-dashed border-card-border p-8 text-center transition hover:border-brand">
                    <input type="file" id="doc-file" accept="application/pdf,image/jpeg,image/png" class="hidden">
                    <p class="text-text">Drop an invoice here, or <span class="font-semibold text-brand">browse</span></p>
                    <p class="mt-1 text-xs text-text-muted">PDF, JPEG or PNG, up to 10MB. One invoice at a time.</p>
                </div>
                <p class="mt-2 text-xs text-text-muted">
                    The document is sent to Anthropic to be read, then deleted. It is not kept.
                    <a href="/privacy#ai" class="underline hover:text-text-strong">What this means</a>.
                </p>

                <?php else: ?>
                <!-- The consent gate. Written to be read, not clicked past: this
                     is the one place in Duely where real client data leaves. -->
                <div class="mt-4 rounded-xl border border-card-border bg-surface-muted p-5">
                    <p class="text-sm text-text">
                        To read a document, Duely sends it to <span class="text-text-strong">Anthropic</span>,
                        the company behind Claude. That means the client's name, the amount, and
                        anything else printed on the invoice leave this server.
                    </p>
                    <p class="mt-3 text-sm text-text-muted">
                        This is different from the writing assistant, which only ever sees your
                        template with placeholders where the real values go. Reading a document
                        cannot work that way, because the details are the thing being read.
                    </p>
                    <p class="mt-3 text-sm text-text-muted">
                        The file is deleted as soon as it has been read, and nothing is saved until
                        you confirm the fields yourself. You can switch this back off at any time.
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" id="extraction-consent" class="btn btn-primary btn-sm">
                            Turn this on
                        </button>
                        <a href="/privacy#ai" class="text-sm text-text-muted hover:text-text-strong">
                            Read what Duely sends
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <div id="doc-error" class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
                <div id="doc-busy" class="mt-4 hidden text-sm text-text-muted">Reading the invoice&hellip;</div>
            </div>
            <?php endif; ?>
        </section>

        <!-- Step 2: preview and map -->
        <section data-step-panel="2" class="hidden space-y-6">
            <div class="rounded-xl border border-card-border bg-card p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold text-text-strong">
                        First <?= (int) $previewRows ?> rows of <span data-file-name></span>
                    </h2>
                    <span class="text-sm text-text-muted"><span data-total-rows>0</span> rows found</span>
                </div>
                <p class="mt-1 text-sm text-text-muted">Nothing has been imported yet.</p>

                <div class="mt-4 overflow-x-auto rounded-lg border border-card-border">
                    <table class="table text-xs">
                        <thead><tr data-preview-headers></tr></thead>
                        <tbody data-preview-rows></tbody>
                    </table>
                </div>

                <div id="truncation-warning" class="mt-3 hidden text-xs text-amber-400"></div>
            </div>

            <div class="rounded-xl border border-card-border bg-card p-6">
                <h2 class="text-lg font-semibold text-text-strong">Match your columns</h2>
                <p class="mt-1 text-sm text-text-muted">
                    We have guessed from your headers. Change anything that looks wrong.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2" data-mapping-fields></div>

                <div id="mapping-error" class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
            </div>

            <!-- Date locale toggle, surfaced only when it actually matters. -->
            <div id="locale-panel" class="hidden rounded-xl border border-amber-500/30 bg-amber-500/10 p-5">
                <p class="font-semibold text-amber-400">Which way round are your dates?</p>
                <p class="mt-1 text-sm text-text-muted">
                    Your file contains dates like <span data-ambiguous-example class="font-mono text-text"></span>,
                    which could mean two different days. Pick the one your spreadsheet uses.
                </p>
                <div class="mt-3 flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm text-text">
                        <input type="radio" name="date_locale" value="mdy" checked>
                        Month first — <span class="font-mono text-xs">MM/DD/YYYY</span> (US)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-text">
                        <input type="radio" name="date_locale" value="dmy">
                        Day first — <span class="font-mono text-xs">DD/MM/YYYY</span> (UK, EU)
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="button" id="validate-button" class="btn btn-primary">Check my file</button>
                <button type="button" data-cancel-import class="btn btn-secondary border border-card-border">Start over</button>
            </div>
        </section>

        <!-- Step 3: validation result and confirm -->
        <section data-step-panel="3" class="hidden space-y-6">
            <div class="rounded-xl border border-card-border bg-card p-6">
                <h2 class="text-lg font-semibold text-text-strong">Here is what will happen</h2>
                <p class="mt-1 text-sm text-text-muted">Still nothing imported. Review, then confirm below.</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-4" data-summary-tiles></div>
            </div>

            <div id="sample-panel" class="rounded-xl border border-card-border bg-card p-6">
                <h3 class="font-semibold text-text-strong">A few rows as they will be saved</h3>
                <p class="mt-1 text-sm text-text-muted">Check the amounts and dates read correctly.</p>
                <div class="mt-4 overflow-x-auto rounded-lg border border-card-border">
                    <table class="table text-sm">
                        <thead>
                            <tr>
                                <th>Line</th><th>Invoice</th><th>Client</th>
                                <th class="text-right">Amount</th><th>Due</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody data-sample-rows></tbody>
                    </table>
                </div>
            </div>

            <!-- Rejected rows, line by line with a reason. -->
            <div id="errors-panel" class="hidden rounded-xl border border-amber-500/30 bg-amber-500/10 p-6">
                <h3 class="font-semibold text-amber-400">
                    <span data-error-count>0</span> rows cannot be imported
                </h3>
                <p class="mt-1 text-sm text-text-muted">
                    The rest will still import. Fix these in your spreadsheet and import again whenever you like —
                    re-importing updates rather than duplicating.
                </p>
                <div class="mt-4 max-h-72 overflow-y-auto rounded-lg border border-card-border bg-card">
                    <table class="table text-sm">
                        <thead><tr><th>Line</th><th>Reason</th><th>Row</th></tr></thead>
                        <tbody data-error-rows></tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" id="commit-button" class="btn btn-primary">
                    Import <span data-commit-count>0</span> invoices
                </button>
                <button type="button" data-back-to-mapping class="btn btn-secondary border border-card-border">
                    Back to columns
                </button>
                <button type="button" data-cancel-import class="btn ml-auto text-sm text-text-muted hover:underline">
                    Cancel
                </button>
            </div>
        </section>

        <!-- Step 4: result -->
        <section data-step-panel="4" class="hidden space-y-6">
            <div class="rounded-xl border border-success-border bg-success-soft p-6">
                <h2 class="text-lg font-semibold text-success-text" data-result-headline></h2>
                <p class="mt-1 text-sm text-text-muted" data-result-detail></p>
            </div>

            <div id="result-errors" class="hidden rounded-xl border border-amber-500/30 bg-amber-500/10 p-6">
                <h3 class="font-semibold text-amber-400">
                    <span data-result-error-count>0</span> rows were skipped
                </h3>
                <div class="mt-4 max-h-72 overflow-y-auto rounded-lg border border-card-border bg-card">
                    <table class="table text-sm">
                        <thead><tr><th>Line</th><th>Reason</th></tr></thead>
                        <tbody data-result-error-rows></tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="/invoices" class="btn btn-primary">See my invoices</a>
                <button type="button" data-cancel-import class="btn btn-secondary border border-card-border">Import another file</button>
            </div>
        </section>
    </div>

    <script>
        window.duelyImportFields = <?= json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</body>
</html>
