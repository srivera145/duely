<?php
/**
 * The gate before signing in as a customer.
 *
 * A reason, then a fresh code to the operator's own address. The code is the
 * part most likely to be argued away as friction. It is not friction: it is the
 * difference between "somebody took the laptop" and "somebody read every
 * customer's invoices", and a session cookie is exactly what a stolen laptop
 * already has.
 */
$target = $target ?? [];
$maxMinutes = (int) ($maxMinutes ?? 30);
$minReason = (int) ($minReason ?? 10);
$codeSent = (bool) ($codeSent ?? false);
$error = $error ?? null;

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrf = \Keel\Core\Csrf::field();

$panelTitle = 'Sign in as ' . ($target['email'] ?? '');
$panelSubtitle = (string) ($target['organization_name'] ?? 'No workspace');
require __DIR__ . '/_layout.php';
?>

        <?php if ($error !== null): ?>
        <div class="mb-4 rounded-lg border border-danger-border bg-danger-soft px-4 py-3 text-sm text-danger-text">
            <?= $e($error) ?>
        </div>
        <?php endif; ?>

        <section class="max-w-xl rounded-lg border border-amber-500/30 bg-card p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-amber-400">What this session can and cannot do</h2>
            <ul class="mt-3 space-y-1 text-sm text-text-muted">
                <li>Can: see every screen exactly as <?= $e($target['email'] ?? 'they') ?> sees it.</li>
                <li>Cannot: send any email, including a test.</li>
                <li>Cannot: start or advance a chase.</li>
                <li>Cannot: change billing, plan, or Stripe.</li>
                <li>Cannot: change mailbox settings, delete anything, or invite users.</li>
                <li>Cannot: reach this panel while it is running.</li>
                <li>Ends after <?= $maxMinutes ?> minutes. No extension.</li>
            </ul>
            <p class="mt-3 text-sm text-text-muted">
                The account owner sees this in their own activity log, with the reason you type below.
            </p>
        </section>

        <section class="mt-4 max-w-xl rounded-lg border border-card-border bg-card p-5">
            <?php if (!$codeSent): ?>
            <!--
                Two steps rather than one form. Requesting the code is a
                deliberate act, and separating it means a half-filled page cannot
                be submitted by a stray keypress.
            -->
            <p class="text-sm text-text-muted">
                A one-time code will be sent to your own address &mdash; not the customer's.
            </p>
            <form method="post" action="/super-admin/impersonate/<?= (int) $target['id'] ?>/code" class="mt-3">
                <?= $csrf ?>
                <button type="submit" class="btn btn-primary">Send me a code</button>
            </form>
            <?php else: ?>
            <form method="post" action="/super-admin/impersonate/<?= (int) $target['id'] ?>" class="space-y-3">
                <?= $csrf ?>

                <label class="block">
                    <span class="text-sm font-medium text-text">Why</span>
                    <input type="text" name="reason" required minlength="<?= $minReason ?>"
                           placeholder="Ticket 412 — reproducing the missing reminder"
                           class="form-input mt-1 w-full">
                    <span class="mt-1 block text-xs text-text-muted">
                        At least <?= $minReason ?> characters. The customer reads this.
                    </span>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-text">Code from your email</span>
                    <input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code"
                           class="form-input mt-1 w-40 font-mono">
                </label>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn btn-primary">Start support session</button>
                    <a href="/super-admin/support" class="text-sm text-text-muted hover:text-text-strong">Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </section>
<?php require __DIR__ . '/_layout-end.php'; ?>
