<?php
/**
 * Duely — sequence editor with a live preview.
 *
 * The step cards are rendered server-side from the stored ladder; the preview
 * panel beside each one re-renders through the API as the user types, so what
 * they see is the same renderer that will produce the real email.
 */
$sequence = $sequence ?? null;
$tags = $tags ?? [];
$sampleContext = $sampleContext ?? [];
$activeChases = $activeChases ?? 0;
$steps = $sequence['steps'] ?? [];
$assistAvailable = $assistAvailable ?? false;
$assistAllowance = $assistAllowance ?? ['used' => 0, 'limit' => 0];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$tones = [
    'polite' => 'Polite — a light nudge',
    'neutral' => 'Neutral — matter of fact',
    'firm' => 'Firm — asking directly',
    'final' => 'Final — the last reminder',
];
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
                <?php if ($activeChases > 0): ?>
                <p class="mt-2 text-sm text-amber-400">
                    Running against <?= (int) $activeChases ?> invoice<?= $activeChases === 1 ? '' : 's' ?> right now.
                    Changes apply to reminders that have not been sent yet.
                </p>
                <?php endif; ?>
        <?php $pageSubtitle = ob_get_clean();
        ob_start(); ?>
            <a href="/sequences" class="text-sm text-text-muted hover:text-text-strong">Back to sequences</a>
        <?php $pageActions = ob_get_clean();
        $pageTitle = $e($sequence['name']);
        $pageEyebrow = 'Sequences';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <form id="sequence-form" class="space-y-6" novalidate>
            <input type="hidden" name="id" value="<?= (int) $sequence['id'] ?>">

            <!-- Sequence settings -->
            <section class="rounded-xl border border-card-border bg-card p-6">
                <h2 class="text-lg font-semibold text-text-strong">Settings</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="block text-sm font-medium text-text">Name</label>
                        <input type="text" id="name" name="name" required
                               value="<?= $e($sequence['name']) ?>" class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="name">Only you see this.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="description" class="block text-sm font-medium text-text">Description</label>
                        <input type="text" id="description" name="description"
                               value="<?= $e($sequence['description']) ?>" class="form-input mt-1 w-full">
                    </div>
                    <div>
                        <label for="send_window_start" class="block text-sm font-medium text-text">Send no earlier than</label>
                        <input type="time" id="send_window_start" name="send_window_start"
                               value="<?= $e(substr((string) $sequence['send_window_start'], 0, 5)) ?>"
                               class="form-input mt-1 w-full font-mono">
                    </div>
                    <div>
                        <label for="send_window_end" class="block text-sm font-medium text-text">Send no later than</label>
                        <input type="time" id="send_window_end" name="send_window_end"
                               value="<?= $e(substr((string) $sequence['send_window_end'], 0, 5)) ?>"
                               class="form-input mt-1 w-full font-mono">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="send_window_end">
                            A reminder that arrives at 3am reads as a robot.
                        </p>
                    </div>
                    <div class="sm:col-span-2 flex flex-wrap gap-6">
                        <label class="flex items-center gap-2 text-sm text-text">
                            <input type="checkbox" name="skip_weekends" class="rounded border-input-border"
                                <?= (int) $sequence['skip_weekends'] === 1 ? 'checked' : '' ?>>
                            Skip weekends
                        </label>
                        <label class="flex items-center gap-2 text-sm text-text">
                            <input type="checkbox" name="is_active" class="rounded border-input-border"
                                <?= (int) $sequence['is_active'] === 1 ? 'checked' : '' ?>>
                            Sequence is active
                        </label>
                    </div>
                </div>
            </section>

            <!-- Merge tag reference -->
            <section class="rounded-xl border border-card-border bg-card p-6">
                <h2 class="text-lg font-semibold text-text-strong">Merge tags</h2>
                <p class="mt-1 text-sm text-text-muted">
                    Click one to drop it into whichever message you were last editing.
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($tags as $tag => $definition): ?>
                    <button type="button" data-insert-tag="<?= $e($tag) ?>"
                            title="<?= $e($definition['label']) ?> — e.g. <?= $e($definition['example']) ?>"
                            class="rounded-lg border border-card-border bg-surface-muted px-2 py-1 font-mono text-xs text-text hover:border-brand">
                        {{<?= $e($tag) ?>}}
                    </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($assistAvailable): ?>
            <!-- Writing assistant. Every draft is a proposal; nothing is saved
                 until the user accepts it and saves the sequence as usual. -->
            <section id="tone-assist" class="rounded-xl border border-card-border bg-card p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold text-text-strong">Writing help</h2>
                    <span class="text-xs text-text-muted" data-assist-allowance>
                        <?= (int) $assistAllowance['used'] ?> of <?= (int) $assistAllowance['limit'] ?> writing requests used today
                    </span>
                </div>
                <p class="mt-1 text-sm text-text-muted">
                    Duely can redraft a reminder or write you a whole ladder. Your client details never
                    leave the app &mdash; only the merge tags are sent.
                </p>

                <div class="mt-5 space-y-4">
                    <div>
                        <label for="assist-description" class="block text-sm font-medium text-text">
                            Draft a whole ladder
                        </label>
                        <textarea id="assist-description" data-assist-description rows="3"
                                  placeholder="I photograph weddings. Most clients are couples paying personally, and I'd rather keep things warm even when they're late."
                                  class="form-input mt-1 w-full text-sm"></textarea>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <button type="button" data-assist-sequence class="btn btn-sm btn-primary">
                                Draft three reminders
                            </button>
                            <span class="text-xs text-text-muted">Replaces all steps below, once you accept.</span>
                        </div>
                    </div>

                    <div>
                        <label for="assist-instruction" class="block text-sm font-medium text-text">
                            Anything specific? (optional)
                        </label>
                        <input type="text" id="assist-instruction" data-assist-instruction
                               placeholder="Shorter, and mention the payment link"
                               class="form-input mt-1 w-full text-sm">
                        <p class="mt-1 text-xs text-text-muted">Applies to the per-step rewrite buttons.</p>
                    </div>
                </div>

                <p data-assist-redactions class="mt-4 hidden rounded-lg border border-card-border bg-surface-muted p-3 text-xs text-text-muted"></p>
                <div data-assist-error class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
                <p data-assist-unsaved class="mt-4 hidden text-sm text-amber-400">
                    Draft loaded into the editor. Nothing is saved until you press Save sequence.
                </p>
            </section>

            <!-- Proposed diff. The user accepts or discards; nothing auto-applies. -->
            <section id="assist-proposal" class="hidden rounded-xl border border-brand/40 bg-card p-6">
                <h2 class="text-lg font-semibold text-text-strong" data-proposal-title></h2>
                <p class="mt-1 text-sm text-text-muted">
                    Nothing has been changed yet. Accept to load this into the editor, or discard it.
                </p>

                <div data-proposal-single class="mt-5 space-y-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-text-muted">Subject</p>
                        <p class="mt-1 text-sm text-text" data-proposal-subject></p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-text-muted">Message</p>
                        <pre class="mt-1 whitespace-pre-wrap font-sans text-sm text-text" data-proposal-body></pre>
                    </div>
                </div>

                <div data-proposal-steps class="mt-5 hidden space-y-3"></div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <button type="button" data-proposal-accept class="btn btn-sm btn-primary">
                        Accept into the editor
                    </button>
                    <button type="button" data-proposal-discard class="btn btn-sm border border-card-border">
                        Discard
                    </button>
                </div>
            </section>
            <?php endif; ?>

            <!-- Steps -->
            <div id="steps" class="space-y-6">
                <?php foreach ($steps as $index => $step): ?>
                <section class="rounded-xl border border-card-border bg-card p-6" data-step="<?= (int) $index ?>">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-text-strong">
                            Reminder <span data-step-number><?= (int) $index + 1 ?></span>
                        </h2>
                        <button type="button" data-remove-step
                                class="text-sm text-text-muted hover:text-danger-text">Remove</button>
                    </div>

                    <div class="mt-5 grid gap-6 lg:grid-cols-2">
                        <!-- Editor -->
                        <div class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-text">Days after due date</label>
                                    <input type="number" name="offset_days" min="-30" max="365"
                                           value="<?= (int) $step['offset_days'] ?>"
                                           class="form-input mt-1 w-full font-mono">
                                    <p class="mt-1 text-xs text-text-muted" data-error-for="offset_days">
                                        Counted from the due date, not from when you imported.
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-text">Tone</label>
                                    <select name="tone" class="form-input mt-1 w-full">
                                        <?php foreach ($tones as $value => $label): ?>
                                        <option value="<?= $e($value) ?>" <?= $step['tone'] === $value ? 'selected' : '' ?>>
                                            <?= $e($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-text">Subject</label>
                                <input type="text" name="subject_template" data-template-field
                                       value="<?= $e($step['subject_template']) ?>"
                                       class="form-input mt-1 w-full">
                                <p class="mt-1 text-xs text-text-muted" data-error-for="subject_template"></p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-text">Message</label>
                                <textarea name="body_template" rows="14" data-template-field
                                          class="form-input mt-1 w-full font-mono text-sm"><?= $e($step['body_template']) ?></textarea>
                                <p class="mt-1 text-xs text-text-muted" data-error-for="body_template"></p>
                            </div>

                            <div data-tag-warning class="hidden rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-400"></div>

                            <?php if ($assistAvailable): ?>
                            <button type="button" data-assist-rewrite
                                    class="btn btn-sm border border-card-border text-xs">
                                Rewrite this with Duely
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Live preview -->
                        <div class="rounded-lg border border-card-border bg-surface-muted p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-text-muted">Preview</p>
                            <p class="mt-1 text-xs text-text-muted">
                                As <?= $e($sampleContext['client_first_name'] ?? 'your client') ?> would see it.
                            </p>

                            <div class="mt-4 rounded-lg border border-card-border bg-card p-4">
                                <p class="text-xs text-text-muted">Subject</p>
                                <p class="mt-1 font-medium text-text-strong" data-preview-subject></p>
                                <hr class="my-3 border-card-border">
                                <div class="text-sm text-text" data-preview-body></div>
                            </div>

                            <div class="mt-3 flex gap-2 text-xs">
                                <button type="button" data-preview-mode="html"
                                        class="rounded border border-brand px-2 py-1 text-text-strong">Formatted</button>
                                <button type="button" data-preview-mode="text"
                                        class="rounded border border-card-border px-2 py-1 text-text-muted">Plain text</button>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>

            <button type="button" id="add-step" class="btn btn-secondary border border-card-border">
                Add another reminder
            </button>

            <div id="sequence-error" class="hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
            <div id="sequence-saved" class="hidden rounded-lg border border-success-border bg-success-soft p-3 text-sm text-success-text"></div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-primary">Save sequence</button>
                <a href="/sequences" class="btn btn-secondary border border-card-border">Cancel</a>
                <?php if ((int) $sequence['is_default'] !== 1): ?>
                <button type="button" id="make-default" class="btn btn-secondary border border-card-border">
                    Make this the default
                </button>
                <?php endif; ?>
                <button type="button" id="delete-sequence" class="btn ml-auto text-sm text-danger-text hover:underline">
                    Delete sequence
                </button>
            </div>
        </form>
    </div>

    <!-- Template for a newly added step, cloned by the editor. -->
    <template id="step-template">
        <section class="rounded-xl border border-card-border bg-card p-6" data-step="new">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-text-strong">
                    Reminder <span data-step-number></span>
                </h2>
                <button type="button" data-remove-step class="text-sm text-text-muted hover:text-danger-text">Remove</button>
            </div>
            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-text">Days after due date</label>
                            <input type="number" name="offset_days" min="-30" max="365" value="45"
                                   class="form-input mt-1 w-full font-mono">
                            <p class="mt-1 text-xs text-text-muted" data-error-for="offset_days"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-text">Tone</label>
                            <select name="tone" class="form-input mt-1 w-full">
                                <?php foreach ($tones as $value => $label): ?>
                                <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-text">Subject</label>
                        <input type="text" name="subject_template" data-template-field
                               value="Invoice {{invoice_number}}" class="form-input mt-1 w-full">
                        <p class="mt-1 text-xs text-text-muted" data-error-for="subject_template"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-text">Message</label>
                        <textarea name="body_template" rows="14" data-template-field
                                  class="form-input mt-1 w-full font-mono text-sm">Hi {{client_first_name}},

Invoice {{invoice_number}} for {{amount}} was due on {{due_date}}.

{{invoice_url}}

Thanks,
{{sender_name}}</textarea>
                        <p class="mt-1 text-xs text-text-muted" data-error-for="body_template"></p>
                    </div>
                    <div data-tag-warning class="hidden rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-400"></div>
                </div>
                <div class="rounded-lg border border-card-border bg-surface-muted p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-text-muted">Preview</p>
                    <div class="mt-4 rounded-lg border border-card-border bg-card p-4">
                        <p class="text-xs text-text-muted">Subject</p>
                        <p class="mt-1 font-medium text-text-strong" data-preview-subject></p>
                        <hr class="my-3 border-card-border">
                        <div class="text-sm text-text" data-preview-body></div>
                    </div>
                    <div class="mt-3 flex gap-2 text-xs">
                        <button type="button" data-preview-mode="html"
                                class="rounded border border-brand px-2 py-1 text-text-strong">Formatted</button>
                        <button type="button" data-preview-mode="text"
                                class="rounded border border-card-border px-2 py-1 text-text-muted">Plain text</button>
                    </div>
                </div>
            </div>
        </section>
    </template>
</body>
</html>
