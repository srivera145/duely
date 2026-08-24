<?php
/**
 * The page a waitlist link lands on.
 *
 * Reached from an email client, so it has to stand on its own: no session, no
 * script, and a way back into the site whichever way it went.
 */
$confirmed = $confirmed ?? false;
$message = $message ?? '';
$state = $state ?? '';
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$heading = match (true) {
    $state === 'unsubscribed' => 'You are off the list',
    $confirmed => 'You are on the list',
    default => 'That link is no longer valid',
};
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="flex min-h-screen flex-col bg-surface text-text antialiased">
<?php $current = ''; require __DIR__ . '/partials/nav.php'; ?>

<main class="flex flex-1 items-center justify-center px-4 py-20">
    <div class="w-full max-w-lg rounded-2xl border border-card-border bg-card p-8 text-center sm:p-10">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full <?=
            $confirmed ? 'bg-brand/15 text-brand' : 'bg-danger-soft text-danger-text' ?>">
            <?php if ($confirmed): ?>
            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 13l4 4L19 7" />
            </svg>
            <?php else: ?>
            <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 8v5M12 16.5v.5" />
                <circle cx="12" cy="12" r="9" />
            </svg>
            <?php endif; ?>
        </span>

        <h1 class="mt-5 text-2xl font-semibold tracking-tight text-text-strong"><?= $e($heading) ?></h1>
        <p class="mt-3 leading-relaxed text-text-muted"><?= $e($message) ?></p>

        <?php if ($confirmed && $state !== 'unsubscribed'): ?>
        <p class="mt-4 text-sm leading-relaxed text-text-muted">
            We will email you once, when your place is ready. In the meantime, the page worth reading
            is the one about
            <a href="/privacy" class="text-brand underline underline-offset-4 hover:text-brand-hover">what Duely does with your mailbox</a>.
        </p>
        <?php endif; ?>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="/" class="rounded-lg bg-brand px-5 py-3 font-semibold text-brand-contrast transition hover:bg-brand-hover">
                Back to Duely
            </a>
            <a href="/how-it-works" class="rounded-lg border border-card-border px-5 py-3 font-semibold text-text-strong transition hover:bg-surface-hover">
                How it works
            </a>
        </div>
    </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
