<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="flex min-h-screen items-center justify-center bg-surface px-4 text-text">
    <div class="w-full max-w-xl rounded-xl border border-card-border bg-card p-8 text-center">
        <p class="text-sm text-text-muted">Billing</p>
        <h1 class="mt-2 text-3xl font-bold text-text-strong">Nothing was charged</h1>
        <p class="mt-4 text-sm leading-6 text-text-muted">
            You left checkout before it finished, so your plan is exactly as it was.
            Everything you have set up is still here.
        </p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="/billing/upgrade" class="btn btn-primary btn-md">Back to the plans</a>
            <a href="/dashboard" class="btn btn-secondary border border-card-border btn-md">Go to the dashboard</a>
        </div>
    </div>
</body>
</html>
