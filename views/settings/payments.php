<?php
/**
 * Duely — collecting payment through the user's own Stripe account.
 *
 * The page is written for someone who has not decided yet, so the unconnected
 * state leads with what actually happens to the money rather than with a
 * button. The connected state is deliberately plain: once it works, there is
 * nothing here worth reading twice.
 */
$status = $status ?? ['connected' => false, 'charges_enabled' => false, 'payouts_enabled' => false];
$configured = $configured ?? false;
$notice = $notice ?? null;
$error = $error ?? null;

$connected = (bool) $status['connected'];
$charges = (bool) $status['charges_enabled'];
$payouts = (bool) $status['payouts_enabled'];
$ready = $connected && $charges;

// The workspace default, which is a separate thing from being connected. A
// workspace can be connected and set to `never`; that is not a fault, and the
// page must not read like one.
$mode = (string) ($status['payment_link_mode'] ?? 'always');

$modeOptions = [
    'always' => [
        'label' => 'Add a pay button to every reminder',
        'hint' => 'Any open invoice without a payment link of its own gets one.',
    ],
    'manual_only' => [
        'label' => 'Only on invoices I choose',
        'hint' => 'Duely makes no links by itself. Switch it on per invoice instead.',
    ],
    'never' => [
        'label' => 'No Duely pay buttons',
        'hint' => 'Nothing generated for any reminder. A link you paste yourself still goes out — that one is yours.',
    ],
];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$notices = [
    'connected' => 'Your Stripe account is linked.',
    'cancelled' => 'Nothing was linked. You can start again whenever you like.',
    'disconnected' => 'Your Stripe account has been unlinked and Duely\'s access revoked.',
    'disconnected_unconfirmed' => 'Unlinked here, but Stripe did not confirm it. Check Connected apps in your Stripe dashboard.',
    'mode_always' => 'Reminders will carry a pay button.',
    'mode_manual_only' => 'Duely will only use payment links you add yourself.',
    'mode_never' => 'Duely pay buttons are off. Stripe is still connected, and links you paste yourself still go out.',
];

$errors = [
    'not_configured' => 'Payments are not set up on this installation yet.',
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-4xl px-4 py-10">
<?php
$pageTitle = 'Payments';
$pageEyebrow = 'Settings';
$pageSubtitle = '<p class="mt-2 max-w-xl text-sm text-text-muted">'
    . 'Let a client pay an invoice straight from the reminder, into your own Stripe account.'
    . '</p>';
require __DIR__ . '/../partials/app-nav.php';
?>

        <?php if ($notice !== null && isset($notices[$notice])): ?>
        <div class="mb-6 rounded-xl border border-success-border bg-success-soft p-4">
            <p class="text-sm text-success-text"><?= $e($notices[$notice]) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
        <div class="mb-6 rounded-xl border border-danger-border bg-danger-soft p-4">
            <p class="text-sm text-danger-text"><?= $e($errors[$error] ?? $error) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!$configured && !$connected): ?>
        <section class="rounded-xl border border-card-border bg-card p-6">
            <h2 class="text-lg font-semibold text-text-strong">Payments are not available yet</h2>
            <p class="mt-2 text-sm text-text-muted">
                This installation has not been set up to connect Stripe accounts. Nothing about your reminders
                changes; they carry whatever payment link you put on an invoice, as they always have.
            </p>
        </section>

        <?php elseif (!$connected): ?>
        <!-- Not connected. The whole product works without this; say so. -->
        <section class="rounded-xl border border-card-border bg-card p-6">
            <h2 class="text-lg font-semibold text-text-strong">Connect your Stripe account</h2>
            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                Duely can add a pay button to your reminders. The money goes directly into your own Stripe
                account &mdash; not into ours, and not through ours. You stay the merchant of record, so
                refunds, disputes and payouts are between you, your client and Stripe, exactly as they would
                be if you had made the link yourself.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                Duely takes nothing on top of what you collect. Stripe charges its own processing fee, which
                depends on your account and your country.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                What Duely gets back is a message from Stripe saying a payment succeeded. That is what marks
                the invoice paid and stops the reminders.
            </p>

            <form method="post" action="/settings/payments/connect" class="mt-6">
                <?= \Keel\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-primary">Connect Stripe</button>
            </form>

            <p class="mt-4 text-xs text-text-muted">
                You will sign in to Stripe and choose which account to link. Nothing is connected until you do.
            </p>

            <?php if ($mode !== 'always'): ?>
            <!--
                The setting outlives the connection, so a user who disconnects
                and comes back does not get a surprise when they reconnect.
            -->
            <p class="mt-4 rounded-lg border border-card-border bg-surface-muted p-3 text-xs text-text-muted">
                Your saved preference is
                <strong class="text-text"><?= $e($modeOptions[$mode]['label']) ?></strong>.
                It will still apply if you connect Stripe again.
            </p>
            <?php endif; ?>
        </section>

        <section class="mt-6 rounded-xl border border-card-border bg-card p-6">
            <h2 class="text-base font-semibold text-text-strong">If you would rather not</h2>
            <p class="mt-2 text-sm text-text-muted">
                Leave this alone and nothing changes. You can still paste your own payment link &mdash; PayPal,
                Wave, a bank page, anything &mdash; onto any invoice, and
                <code class="rounded bg-surface-muted px-1 py-0.5 text-xs">{{invoice_url}}</code> will use it.
            </p>
        </section>

        <?php else: ?>
        <!-- Connected. -->
        <section class="rounded-xl border border-card-border bg-card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-text-strong">Stripe is connected</h2>
                    <p class="mt-1 text-sm text-text-muted">
                        Account <span class="font-mono text-xs text-text"><?= $e($status['account_id']) ?></span>
                        <?php if (!empty($status['connected_at'])): ?>
                        &middot; linked <?= $e(substr((string) $status['connected_at'], 0, 10)) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php
                // Three states, not two. A workspace that is connected and set
                // to `never` is working exactly as asked, and a warning badge
                // would read as a fault.
                [$badgeClass, $badgeLabel] = match (true) {
                    !$charges => ['border-amber-500/30 bg-amber-500/10 text-amber-400', 'Not taking payments yet'],
                    $mode === 'never' => ['border-card-border bg-surface-muted text-text-muted', 'Pay buttons off'],
                    $mode === 'manual_only' => ['border-card-border bg-surface-muted text-text-muted', 'Your links only'],
                    default => ['border-success-border bg-success-soft text-success-text', 'Taking payments'],
                };
                ?>
                <span class="shrink-0 rounded-full border px-3 py-1 text-xs font-semibold <?= $badgeClass ?>">
                    <?= $badgeLabel ?>
                </span>
            </div>

            <?php if (!$charges): ?>
            <!--
                Stripe is not letting this account charge. Said plainly, with no
                link offered: a pay button that fails at checkout is worse than
                no button, because the client has already decided to pay.
            -->
            <div class="mt-5 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
                <p class="font-semibold text-amber-400">Stripe is not letting this account take payments yet</p>
                <p class="mt-2 text-sm text-text-muted">
                    This is usually verification Stripe still needs from you &mdash; identity, a bank account,
                    or business details. Until it is done, Duely will not put a pay button on your reminders.
                    Your reminders still go out; they just go out without one.
                </p>
                <p class="mt-2 text-sm text-text-muted">
                    Sign in to Stripe and finish what it asks for. When it is done, use Recheck below.
                </p>
            </div>
            <?php elseif (!$payouts): ?>
            <div class="mt-5 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
                <p class="font-semibold text-amber-400">Payments will work, but payouts are on hold</p>
                <p class="mt-2 text-sm text-text-muted">
                    Stripe can take your clients' money but cannot pay it out to you yet. That is between you
                    and Stripe; Duely cannot see why.
                </p>
            </div>
            <?php elseif ($mode === 'never'): ?>
            <!-- Off because the user said so. Stated as a setting, not a problem. -->
            <p class="mt-5 text-sm leading-relaxed text-text-muted">
                Duely is not putting pay buttons on your reminders, because that is what you asked for below.
                Stripe stays connected, so you can turn this back on without reconnecting anything.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                Payment links you paste onto an invoice yourself still go out. That URL is yours, and this
                setting does not touch it.
            </p>
            <?php elseif ($mode === 'manual_only'): ?>
            <p class="mt-5 text-sm leading-relaxed text-text-muted">
                Duely is not generating links on its own. Reminders carry a pay button only on invoices where
                you have asked for one, or where you pasted your own link.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                When a client pays in full, Duely marks the invoice paid and stops chasing it.
            </p>
            <?php else: ?>
            <p class="mt-5 text-sm leading-relaxed text-text-muted">
                Reminders for open invoices carry a pay button. When a client pays in full, Duely marks the
                invoice paid and stops chasing it. If they pay part of it, the invoice stays open and you get an
                email &mdash; Duely will not guess whether the rest is coming.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                Invoices where you pasted your own link keep using your link. Duely never replaces it.
            </p>
            <?php endif; ?>

            <!--
                Radios, not a checkbox. There are three states and the middle one
                is the interesting one -- keep Stripe connected, but decide per
                invoice -- and a checkbox cannot say it.
            -->
            <form method="post" action="/settings/payments/mode" class="mt-6 border-t border-card-border pt-6">
                <?= \Keel\Core\Csrf::field() ?>
                <fieldset>
                    <legend class="text-sm font-semibold text-text-strong">When should Duely add a pay button?</legend>
                    <p class="mt-1 text-sm text-text-muted">
                        This governs links Duely creates. A payment link you paste onto an invoice is your own,
                        and none of these settings suppress it.
                    </p>

                    <div class="mt-4 space-y-3">
                        <?php foreach ($modeOptions as $value => $option): ?>
                        <label class="flex cursor-pointer gap-3 rounded-lg border border-card-border p-4 transition hover:border-brand">
                            <input type="radio" name="payment_link_mode" value="<?= $e($value) ?>"
                                   class="mt-1 shrink-0 accent-brand"
                                   <?= $mode === $value ? 'checked' : '' ?>>
                            <span>
                                <span class="block text-sm font-medium text-text-strong"><?= $e($option['label']) ?></span>
                                <span class="mt-1 block text-sm text-text-muted"><?= $e($option['hint']) ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-primary mt-4">Save</button>
                </fieldset>
            </form>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <button type="button" id="recheck-button" class="btn">Recheck with Stripe</button>
                <form method="post" action="/settings/payments/disconnect" class="ml-auto"
                      onsubmit="return confirm('Unlink your Stripe account? Pay buttons Duely generated will be removed from your invoices. Any link you pasted yourself is kept.');">
                    <?= \Keel\Core\Csrf::field() ?>
                    <button type="submit" class="btn text-sm text-danger-text hover:underline">Disconnect Stripe</button>
                </form>
            </div>
        </section>

        <section class="mt-6 rounded-xl border border-card-border bg-card p-6">
            <h2 class="text-base font-semibold text-text-strong">What Duely does and does not do</h2>
            <ul class="mt-3 space-y-2 text-sm text-text-muted">
                <li>Your clients pay into <strong class="text-text">your</strong> Stripe account. The money never passes through Duely's.</li>
                <li>You are the merchant of record. Refunds, chargebacks and disputes are between you, your client and Stripe.</li>
                <li>Duely charges nothing on top of what you collect. Stripe charges its own processing fee.</li>
                <li>Duely is told that a payment succeeded, and how much. That is the whole of its involvement with the money.</li>
            </ul>
            <p class="mt-4 text-sm text-text-muted">
                <a href="/privacy#payments" class="text-brand hover:underline">How this is described on the privacy page</a>
            </p>
        </section>
        <?php endif; ?>
    </div>

    <?php if ($connected): ?>
    <script>
        // Recheck asks Stripe what this account can do now, rather than
        // trusting a flag that may be days old.
        document.getElementById('recheck-button')?.addEventListener('click', async function (event) {
            const button = event.currentTarget;
            button.disabled = true;
            button.textContent = 'Checking…';

            try {
                const response = await fetch('/settings/payments/refresh', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': <?= json_encode(\Keel\Core\Csrf::token()) ?>
                    }
                });

                if (response.ok) {
                    window.location.reload();
                    return;
                }
            } catch (error) {
                // Fall through to the reset below.
            }

            button.disabled = false;
            button.textContent = 'Recheck with Stripe';
        });
    </script>
    <?php endif; ?>
</body>
</html>
