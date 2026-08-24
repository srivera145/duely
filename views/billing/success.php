<?php
/**
 * Duely — post-checkout.
 *
 * The plan is granted by the webhook, never by this redirect, so this page is
 * careful not to promise something the webhook has not yet confirmed.
 */
$status = $status ?? [];
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="flex min-h-screen items-center justify-center bg-surface px-4 text-text">
    <div class="w-full max-w-xl rounded-xl border border-card-border bg-card p-8 text-center">
        <?php if (!empty($status['is_founding'])): ?>
        <p class="text-sm text-brand">Founding member #<?= (int) $status['founding_slot'] ?></p>
        <h1 class="mt-2 text-3xl font-bold text-text-strong">Your price is locked</h1>
        <p class="mt-4 text-sm leading-6 text-text-muted">
            $19 a month for as long as you stay, whatever we charge later. Thank you
            for backing this early.
        </p>
        <?php else: ?>
        <p class="text-sm text-text-muted">Billing</p>
        <h1 class="mt-2 text-3xl font-bold text-text-strong">You are on <?= $e($status['plan_name'] ?? 'your new plan') ?></h1>
        <p class="mt-4 text-sm leading-6 text-text-muted">
            Duely will keep following up on your overdue invoices, and stop the moment
            a client replies or an invoice is paid.
        </p>
        <?php endif; ?>

        <p class="mt-4 text-sm text-text-muted">
            Stripe confirms the change by webhook rather than by this page. If it has not
            caught up yet, give it a moment and refresh.
        </p>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="/dashboard" class="btn btn-primary btn-md">Go to the dashboard</a>
            <a href="/billing/upgrade" class="btn btn-secondary border border-card-border btn-md">Back to billing</a>
        </div>
    </div>
</body>
</html>
