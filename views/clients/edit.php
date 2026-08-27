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

        <?php
        $pageActions = '<a href="/clients" class="text-sm text-text-muted hover:text-text-strong">Back to clients</a>';
        $pageEyebrow = 'Clients';
        $pageEyebrowHref = '/clients';
        $pageTitle = $isNew ? 'New client' : $e($client['name']);
        require __DIR__ . '/../partials/app-nav.php';
        ?>

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
                        <?php
                        $timezones = $timezones ?? [];
                        $workspaceTimezone = $workspaceTimezone ?? 'UTC';
                        // A new client defaults to the workspace, not to UTC.
                        // Most of a freelancer's clients are near them, and UTC
                        // is the wrong guess for almost everyone.
                        $clientTimezone = $isNew
                            ? $workspaceTimezone
                            : ($client['timezone'] ?: $workspaceTimezone);
                        ?>
                        <label for="timezone" class="block text-sm font-medium text-text">Their timezone</label>
                        <select id="timezone" name="timezone" class="form-input mt-1 w-full">
                            <?php foreach ($timezones as $group => $entries): ?>
                            <optgroup label="<?= $e($group) ?>">
                                <?php foreach ($entries as $entry): ?>
                                <option value="<?= $e($entry['value']) ?>"
                                    <?= $entry['value'] === $clientTimezone ? 'selected' : '' ?>>
                                    <?= $e($entry['label']) ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <!--
                            The comparison is the point. Reminders go out between
                            09:00 and 16:00 in the client's zone, so a user needs
                            to see that nine in the morning for this client is
                            four in the afternoon for them -- otherwise the
                            window setting reads as if it were about their day.
                        -->
                        <p class="mt-1 text-xs text-text-muted" data-timezone-hint>
                            Reminders go out during their working hours, not yours.
                        </p>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="notes" class="block text-sm font-medium text-text">Notes</label>
                        <textarea id="notes" name="notes" rows="3" class="form-input mt-1 w-full"><?= $isNew ? '' : $e($client['notes']) ?></textarea>
                        <p class="mt-1 text-xs text-text-muted">Private to you — never sent to the client.</p>
                    </div>
                </div>
            </section>

            <script>
                (function () {
                    var select = document.getElementById('timezone');
                    var hint = document.querySelector('[data-timezone-hint]');
                    if (!select || !hint) return;

                    var workspace = <?= json_encode($workspaceTimezone) ?>;

                    var at = function (zone, hour) {
                        // A fixed 09:00 on the client's day, read back as a wall
                        // clock in the other zone. Built from today's date so
                        // the answer is right on both sides of a DST change.
                        try {
                            var parts = new Intl.DateTimeFormat('en-CA', {
                                timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit'
                            }).formatToParts(new Date()).reduce(function (acc, p) {
                                acc[p.type] = p.value; return acc;
                            }, {});

                            var guess = new Date(parts.year + '-' + parts.month + '-' + parts.day
                                + 'T' + String(hour).padStart(2, '0') + ':00:00Z');

                            // Solve for the instant whose local hour in `zone`
                            // is `hour`, by measuring the offset and correcting.
                            var shown = new Date(guess.toLocaleString('en-US', { timeZone: zone }));
                            var utcShown = new Date(guess.toLocaleString('en-US', { timeZone: 'UTC' }));
                            return new Date(guess.getTime() + (utcShown - shown));
                        } catch (error) {
                            return null;
                        }
                    };

                    var format = function (date, zone) {
                        return new Intl.DateTimeFormat('en-GB', {
                            timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false
                        }).format(date);
                    };

                    var update = function () {
                        var zone = select.value;
                        var moment = at(zone, 9);

                        if (!moment) { return; }

                        if (zone === workspace) {
                            hint.textContent = 'Same timezone as your workspace.';
                            return;
                        }

                        hint.textContent = '09:00 for them is '
                            + format(moment, workspace) + ' for you ('
                            + workspace + ').';
                    };

                    select.addEventListener('change', update);
                    update();
                })();
            </script>

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
