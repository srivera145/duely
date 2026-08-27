<?php
/**
 * The front door to support access.
 *
 * The reason box is the whole page. Nothing opens without one, and it is shown
 * to the customer afterwards — which is the point. Stating a purpose out loud,
 * into something the subject will read, is what separates support from browsing.
 */
$organizations = $organizations ?? [];
$error = $error ?? null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$panelTitle = 'Support access';
$panelSubtitle = 'Every access is recorded and shown to the account owner.';
require __DIR__ . '/_layout.php';
?>

        <?php if ($error !== null): ?>
        <div class="mb-4 rounded-lg border border-danger-border bg-danger-soft px-4 py-3 text-sm text-danger-text">
            <?= $e($error) ?>
        </div>
        <?php endif; ?>

        <section class="rounded-lg border border-card-border bg-card p-4">
            <form method="post" action="/super-admin/support/open" class="space-y-3">
                <?= \Keel\Core\Csrf::field() ?>

                <label class="block">
                    <span class="text-sm font-medium text-text">Workspace</span>
                    <select name="tenant_id" required class="form-input mt-1 w-full max-w-md">
                        <option value="">Choose one…</option>
                        <?php foreach ($organizations as $organization): ?>
                        <option value="<?= (int) $organization['id'] ?>">
                            <?= $e($organization['name']) ?> (#<?= (int) $organization['id'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-text">Why</span>
                    <input type="text" name="reason" required minlength="10"
                           placeholder="Ticket 412 — reminders not sending since Tuesday"
                           class="form-input mt-1 w-full max-w-md">
                    <span class="mt-1 block text-xs text-text-muted">
                        At least 10 characters. Stored with the record and shown to the account owner
                        in their own activity log.
                    </span>
                </label>

                <button type="submit" class="btn btn-primary">Open account</button>
            </form>
        </section>

        <section class="mt-4 rounded-lg border border-card-border bg-card p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-text-muted">What this does not show</h2>
            <ul class="mt-2 space-y-1 text-sm text-text-muted">
                <li>Mailbox passwords. Never displayed, never decrypted, no masked or partial version.</li>
                <li>Invoice and client contents. Counts only, unless you sign in as a user.</li>
                <li>Message bodies. What was sent is visible to the customer, not from here.</li>
            </ul>
        </section>
<?php require __DIR__ . '/_layout-end.php'; ?>
