<?php
/**
 * The workspace's display timezone.
 *
 * Presentation only, and the page says so, because the obvious worry when
 * changing a timezone is "does this move my reminders?". It does not: storage
 * stays UTC, delivery is timed against each client's own zone, and this setting
 * changes only what a timestamp is labelled.
 */
$current = $current ?? 'UTC';
$timezones = $timezones ?? [];
$nowLocal = $nowLocal ?? '';
$nowUtc = $nowUtc ?? '';
$clientsOnDefault = (int) ($clientsOnDefault ?? 0);
$notice = $notice ?? null;
$error = $error ?? null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
<?php require __DIR__ . '/../partials/nav-bar.php'; ?>
    <div class="mx-auto max-w-3xl px-4 py-10">
<?php
$pageEyebrow = 'Settings';
$pageTitle = 'Timezone';
$pageSubtitle = '<p class="mt-2 max-w-xl text-sm text-text-muted">'
    . 'How Duely labels the times it shows you.'
    . '</p>';
require __DIR__ . '/../partials/app-nav.php';
?>

        <?php if ($notice !== null): ?>
        <div class="mb-6 rounded-xl border border-success-border bg-success-soft p-4">
            <p class="text-sm text-success-text"><?= $e($notice) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
        <div class="mb-6 rounded-xl border border-danger-border bg-danger-soft p-4">
            <p class="text-sm text-danger-text"><?= $e($error) ?></p>
        </div>
        <?php endif; ?>

        <section class="rounded-xl border border-card-border bg-card p-6">
            <form method="post" action="/settings/timezone">
                <?= \Keel\Core\Csrf::field() ?>

                <label for="timezone" class="block text-sm font-medium text-text">Show times in</label>
                <select id="timezone" name="timezone" class="form-input mt-2 w-full max-w-md">
                    <?php foreach ($timezones as $group => $entries): ?>
                    <optgroup label="<?= $e($group) ?>">
                        <?php foreach ($entries as $entry): ?>
                        <option value="<?= $e($entry['value']) ?>" <?= $entry['value'] === $current ? 'selected' : '' ?>>
                            <?= $e($entry['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>

                <p class="mt-2 text-sm text-text-muted">
                    It is <strong class="text-text"><?= $e($nowLocal) ?></strong> in
                    <?= $e($current) ?><?php if ($current !== 'UTC'): ?>,
                    and <?= $e($nowUtc) ?> UTC<?php endif; ?>.
                </p>

                <button type="submit" class="btn btn-primary mt-4">Save</button>
            </form>
        </section>

        <!--
            Said plainly, because "changing the timezone" sounds like it might
            move something. It does not, and a user should not have to test it
            on their own clients to find that out.
        -->
        <section class="mt-6 rounded-xl border border-card-border bg-card p-6">
            <h2 class="text-base font-semibold text-text-strong">What this does and does not change</h2>
            <ul class="mt-3 space-y-2 text-sm text-text-muted">
                <li>Changes what every timestamp in Duely is labelled &mdash; dashboard, invoice
                    timeline, activity log.</li>
                <li>Does <strong class="text-text">not</strong> move any reminder. Nothing is
                    rescheduled, and nothing in the database changes.</li>
                <li>Does <strong class="text-text">not</strong> decide when reminders go out. That is
                    timed against each client&rsquo;s own timezone, so nine in the morning means nine
                    in the morning where they are.</li>
                <li>New clients default to this timezone instead of UTC.</li>
            </ul>
        </section>

        <?php if ($current !== 'UTC' && $clientsOnDefault > 0): ?>
        <!--
            The backfill, surfaced rather than performed. Existing clients are all
            UTC by default, which is almost certainly wrong now the workspace is
            not -- but "almost certainly" is not good enough to move somebody's
            reminders by six hours without asking.
        -->
        <section class="mt-6 rounded-xl border border-amber-500/30 bg-amber-500/10 p-6">
            <h2 class="text-base font-semibold text-amber-400">
                <?= $e($clientsOnDefault) ?>
                <?= $clientsOnDefault === 1 ? 'client is' : 'clients are' ?> still set to UTC
            </h2>
            <p class="mt-2 text-sm text-text-muted">
                They were created before this workspace had a timezone, so their reminders are timed
                against UTC rather than against <?= $e($current) ?>. That is probably wrong, but Duely
                will not guess: some of your clients may genuinely be elsewhere.
            </p>
            <p class="mt-2 text-sm text-text-muted">
                You can move them all at once, or set them individually on the clients page.
            </p>

            <form method="post" action="/clients/timezone-backfill" class="mt-4">
                <?= \Keel\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-primary">
                    Set all <?= $e($clientsOnDefault) ?> to <?= $e($current) ?>
                </button>
            </form>
        </section>
        <?php endif; ?>
    </div>
</body>
</html>
