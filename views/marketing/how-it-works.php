<?php
/**
 * How it works.
 *
 * Four steps, then the two things people worry about: what stops it, and what
 * it can see.
 */
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
// The same four steps the HowTo markup describes.
$steps = $steps ?? [];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text antialiased">
<?php $current = 'how-it-works'; require __DIR__ . '/partials/nav.php'; ?>

<main>
    <section class="mx-auto max-w-3xl px-4 pb-12 pt-12 sm:pt-16">
        <h1 class="text-4xl font-semibold leading-tight tracking-tight text-text-strong sm:text-5xl">
            Ten minutes to set up. Then you stop thinking about it.
        </h1>
        <p class="mt-6 text-lg leading-relaxed text-text-muted">
            Duely does one job: it sends the follow-up you keep putting off, from your own email
            address, and it stops the moment it should.
        </p>
    </section>

    <!-- The four steps -->
    <section class="mx-auto max-w-3xl px-4 pb-16">
        <ol class="space-y-10">
            <?php foreach ($steps as $index => $step): ?>
            <li class="flex gap-5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-brand text-sm font-semibold text-brand">
                    <?= $index + 1 ?>
                </span>
                <div>
                    <h2 class="text-xl font-semibold text-text-strong"><?= $e($step['title']) ?></h2>
                    <p class="mt-2 leading-relaxed text-text-muted"><?= $e($step['body']) ?></p>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- What it looks like -->
    <section class="border-y border-card-border bg-surface-muted">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                <div>
                    <h2 class="text-3xl font-semibold tracking-tight text-text-strong">
                        One invoice, mid-chase
                    </h2>
                    <p class="mt-4 leading-relaxed text-text-muted">
                        Two reminders have gone out from the freelancer's own address. The third is
                        scheduled. If Ellie replies to either of the first two &mdash; even with
                        "sorry, next week" &mdash; the third never leaves.
                    </p>
                    <p class="mt-4 leading-relaxed text-text-muted">
                        Everything Duely sends sits in the same email thread, so your client sees one
                        conversation rather than three unrelated messages.
                    </p>
                </div>
                <div class="flex justify-center lg:justify-end">
                    <?php require __DIR__ . '/partials/invoice-card.php'; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Hard stops -->
    <section class="mx-auto max-w-3xl px-4 py-16">
        <h2 class="text-3xl font-semibold tracking-tight text-text-strong">What stops a chase</h2>
        <p class="mt-4 leading-relaxed text-text-muted">
            Checked immediately before every single send, not on a schedule. If any of these is true
            at the moment a message is about to go out, it does not go out.
        </p>

        <ul class="mt-8 space-y-4">
            <?php
            $stops = [
                'The client replied' => 'Any genuine reply in the thread pauses the sequence and '
                    . 'tells you about it.',
                'The invoice was paid' => 'Marked paid in Duely, and the chase stops the same second.',
                'The message bounced' => 'A hard bounce stops the chase and flags the address as bad '
                    . 'rather than retrying into a wall.',
                'You paused it' => 'One click, from the dashboard or the invoice.',
                'The sequence finished' => 'After the final message, Duely stops. It does not loop.',
            ];
            foreach ($stops as $heading => $body):
            ?>
            <li class="flex gap-4 rounded-xl border border-card-border bg-card p-5">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
                <div>
                    <h3 class="font-medium text-text-strong"><?= $e($heading) ?></h3>
                    <p class="mt-1 text-text-muted"><?= $e($body) ?></p>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>

        <p class="mt-8 leading-relaxed text-text-muted">
            An out-of-office reply is not a reply. Duely can tell the difference, so a fortnight
            away does not silently cancel your follow-ups.
        </p>
    </section>

    <!-- Closing -->
    <section class="mx-auto max-w-3xl px-4 pb-20">
        <div class="rounded-2xl border border-card-border bg-card p-8 sm:p-10">
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">
                Join the waitlist
            </h2>
            <p class="mt-3 leading-relaxed text-text-muted">
                One email to confirm, then one when your place is ready. Nothing else.
            </p>
            <div id="waitlist" class="mt-6 max-w-lg scroll-mt-24">
                <?php
                $source = 'how_it_works';
                $landingPath = '/how-it-works';
                require __DIR__ . '/partials/waitlist-form.php';
                ?>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
