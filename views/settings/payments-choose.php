<?php
/**
 * The one screen after connecting Stripe.
 *
 * Its whole job is that nobody finds out what Duely does with their Stripe
 * account by reading a client's inbox. Connecting on Tuesday used to mean a pay
 * button on Wednesday's reminders, chosen by nobody.
 *
 * It does not block. `payment_link_mode` already holds `always`, so a user who
 * reads this and closes the tab is in exactly the state described below — not in
 * a half-configured one.
 */
$status = $status ?? ['payment_link_mode' => 'always'];
$openInvoices = (int) ($openInvoices ?? 0);
$mode = (string) ($status['payment_link_mode'] ?? 'always');

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$options = [
    'always' => [
        'label' => 'Add a pay button to every reminder',
        'hint' => 'Any open invoice without a payment link of its own gets one. You can still turn it off per invoice.',
    ],
    'manual_only' => [
        'label' => 'Only on invoices I choose',
        'hint' => 'Duely makes no links by itself. Stripe stays connected, and you switch it on per invoice.',
    ],
    'never' => [
        'label' => 'Not for now',
        'hint' => 'No pay buttons on any reminder. Stripe stays connected, and a link you paste yourself still goes out.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-2xl px-4 py-10">
<?php
$pageEyebrow = 'Payments';
$pageTitle = 'Stripe is connected';
$pageSubtitle = '<p class="mt-2 text-sm text-text-muted">One thing to decide before your next reminder goes out.</p>';
require __DIR__ . '/../partials/app-nav.php';
?>

        <section class="rounded-xl border border-card-border bg-card p-6">
            <h2 class="text-lg font-semibold text-text-strong">What happens next</h2>

            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                <?php if ($openInvoices > 0): ?>
                You have <strong class="text-text"><?= $e($openInvoices) ?></strong>
                open <?= $openInvoices === 1 ? 'invoice' : 'invoices' ?>. Unless you say otherwise, the next
                reminder for each one will carry a button your client can pay through.
                <?php else: ?>
                Unless you say otherwise, reminders for your open invoices will carry a button your client can
                pay through.
                <?php endif; ?>
            </p>

            <p class="mt-3 text-sm leading-relaxed text-text-muted">
                The money goes straight into your own Stripe account. Duely adds nothing on top of Stripe's own
                processing fee. Any invoice where you have pasted your own payment link keeps using yours.
            </p>

            <form method="post" action="/settings/payments/mode" class="mt-6 space-y-3">
                <?= \Keel\Core\Csrf::field() ?>

                <?php foreach ($options as $value => $option): ?>
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

                <div class="flex flex-wrap items-center gap-3 pt-2">
                    <button type="submit" class="btn btn-primary">Save and continue</button>
                    <!--
                        Leaving is a valid answer. The column already says
                        `always`, which is what the paragraph above describes, so
                        walking away is not an undefined state.
                    -->
                    <a href="/settings/payments" class="text-sm text-text-muted hover:text-text-strong">
                        Leave it as it is
                    </a>
                </div>
            </form>
        </section>

        <p class="mt-4 text-xs text-text-muted">
            You can change this at any time on the payments settings page, and override it on any single invoice.
        </p>
    </div>
</body>
</html>
