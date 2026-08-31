<?php
/**
 * Duely — the landing page.
 *
 * One promise at the top, one objection handled immediately below it, and one
 * form. Everything else on the page exists to make the form less frightening.
 */
$founding = $founding ?? null;
// Rendered from the same array the FAQPage markup is built from, so the
// structured data always describes what is actually on the page.
$faq = $faq ?? [];
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text antialiased">
<?php $current = 'home'; require __DIR__ . '/partials/nav.php'; ?>

<main>
    <!-- Hero -->
    <section class="mx-auto max-w-6xl px-4 pb-16 pt-12 sm:pt-16 lg:pt-24">
        <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
            <div>
                <div class="mb-5">
                    <?php $foundingTone = 'badge'; require __DIR__ . '/partials/founding-note.php'; ?>
                </div>

                <h1 class="text-balance text-4xl font-semibold leading-[1.1] tracking-tight text-text-strong sm:text-5xl lg:text-6xl">
                    Get paid without writing the awkward follow-up.
                </h1>

                <p class="mt-6 max-w-xl text-lg leading-relaxed text-text-muted">
                    For freelancers and small studios who track invoices in a spreadsheet, not an
                    accounting suite. Duely chases the overdue ones for you &mdash; politely, on a
                    schedule &mdash; and stops the second a client replies or pays.
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <a href="/signup"
                       class="rounded-lg bg-brand px-6 py-3 text-base font-semibold text-brand-contrast transition hover:bg-brand-hover">
                        Create your account
                    </a>
                    <a href="/how-it-works"
                       class="text-sm text-text-muted underline decoration-card-border underline-offset-4 hover:text-text-strong">
                        See how it works
                    </a>
                </div>

                <p class="mt-6 text-sm text-text-muted">
                    <?php if ($founding !== null && $founding['remaining'] > 0): ?>
                    <!--
                        The counter is claimed on signup, not on payment, so this
                        sentence is literally true. Saying "the first 50 to sign
                        up" while counting payments would be a nicer-sounding
                        number attached to a different fact.
                    -->
                    Signing up takes your founding place &middot; No card, no password &middot;
                    <?php else: ?>
                    No card to start &middot; Free for three invoices at a time &middot;
                    <?php endif; ?>
                    <a href="/pricing" class="underline decoration-card-border underline-offset-4 hover:text-text-strong">See pricing</a>
                </p>
            </div>

            <div class="flex justify-center lg:justify-end">
                <?php require __DIR__ . '/partials/invoice-card.php'; ?>
            </div>
        </div>
    </section>

    <!-- The differentiator, immediately -->
    <section class="border-y border-card-border bg-surface-muted">
        <div class="mx-auto max-w-6xl px-4 py-16">
            <div class="grid gap-10 lg:grid-cols-[1.1fr_1fr] lg:gap-16">
                <div>
                    <h2 class="text-3xl font-semibold tracking-tight text-text-strong sm:text-4xl">
                        It sends from your own inbox, not ours.
                    </h2>
                    <p class="mt-5 text-lg leading-relaxed text-text-muted">
                        This is the whole point. Duely connects to your mailbox and sends the
                        reminder as you. Your client sees a message from your address, in the same
                        thread as the invoice, and replies to you &mdash; not to a robot at a domain
                        they have never heard of.
                    </p>
                    <p class="mt-4 text-lg leading-relaxed text-text-muted">
                        Which means it lands in the inbox rather than in promotions, it looks like
                        the follow-up you would have written at 11pm on a Sunday, and the
                        relationship stays yours.
                    </p>
                </div>

                <ul class="space-y-5">
                    <?php
                    $points = [
                        'Your address on the envelope' => 'Reminders come from you@yourstudio.com. '
                            . 'Sent items, reply-to, threading — all yours.',
                        'Your deliverability, not a shared pool' => 'No bulk-sender domain to be '
                            . 'lumped in with. It is the same route your own email takes.',
                        'Read-only on your mailbox' => 'Duely watches for replies and bounces. It '
                            . 'never marks anything read, never deletes, never moves anything.',
                        'Credentials encrypted at rest' => 'Your mailbox password is encrypted with '
                            . 'a key that lives outside the database, and decrypted only to send.',
                    ];
                    foreach ($points as $heading => $body):
                    ?>
                    <li class="flex gap-4">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
                        <div>
                            <h3 class="font-medium text-text-strong"><?= $e($heading) ?></h3>
                            <p class="mt-1 text-text-muted"><?= $e($body) ?></p>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <p class="mt-10 text-sm text-text-muted">
                Handing over a mailbox is a big ask. We wrote
                <a href="/privacy" class="text-brand underline underline-offset-4 hover:text-brand-hover">exactly what Duely reads and stores</a>
                in plain English rather than burying it in a policy.
            </p>
        </div>
    </section>

    <!-- The ladder -->
    <section class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="max-w-2xl text-3xl font-semibold tracking-tight text-text-strong sm:text-4xl">
            Polite, then firm, then done.
        </h2>
        <p class="mt-4 max-w-2xl text-lg leading-relaxed text-text-muted">
            Three messages over a month, each a little more direct than the last. No threats, no
            capital letters, no legal language &mdash; just the note you keep meaning to send.
        </p>

        <div class="mt-12 grid gap-6 md:grid-cols-3">
            <?php
            $ladder = [
                ['day' => 3, 'tone' => 'Polite', 'body' => 'A light touch. Invoices get missed, and '
                    . 'most of them are paid the day someone is reminded they exist.'],
                ['day' => 14, 'tone' => 'Firmer', 'body' => 'Names the amount and the date it was '
                    . 'due, and asks for a date. Still friendly, but no longer vague.'],
                ['day' => 30, 'tone' => 'Final', 'body' => 'Factual and short. States where things '
                    . 'stand and what happens next. Firm is not the same as hostile.'],
            ];
            foreach ($ladder as $rung):
            ?>
            <article class="rounded-xl border border-card-border bg-card p-6">
                <p class="text-sm font-medium text-brand">Day <?= (int) $rung['day'] ?></p>
                <h3 class="mt-2 text-xl font-semibold text-text-strong"><?= $e($rung['tone']) ?></h3>
                <p class="mt-3 leading-relaxed text-text-muted"><?= $e($rung['body']) ?></p>
            </article>
            <?php endforeach; ?>
        </div>

        <p class="mt-8 text-text-muted">
            Every word is editable, and
            <a href="/how-it-works" class="text-brand underline underline-offset-4 hover:text-brand-hover">the whole sequence is shown to you</a>
            before anything goes out.
        </p>
    </section>

    <!-- The failure mode everyone worries about -->
    <section class="border-y border-card-border bg-surface-muted">
        <div class="mx-auto max-w-3xl px-4 py-16 text-center">
            <h2 class="text-3xl font-semibold tracking-tight text-text-strong sm:text-4xl">
                It stops when they reply.
            </h2>
            <p class="mt-5 text-lg leading-relaxed text-text-muted">
                The worst thing invoice automation can do is send a final notice to someone who
                emailed you on Friday to say the payment was going out. Duely watches your mailbox
                for replies and pauses the sequence before the next message is written.
            </p>
            <p class="mt-4 text-lg leading-relaxed text-text-muted">
                Paid invoices stop it. Bounces stop it and flag the address. Out-of-office replies
                do not &mdash; an autoresponder is not a person.
            </p>
        </div>
    </section>

    <!--
        Getting paid from the reminder. Secondary on purpose: the product is
        still "get paid without writing the awkward follow-up", and this is an
        add-on to that rather than a second headline.

        Renders only where Stripe Connect is configured.
    -->
    <?php require __DIR__ . '/partials/payments-note.php'; ?>

    <!-- Questions -->
    <section class="mx-auto max-w-3xl px-4 py-20">
        <h2 class="text-3xl font-semibold tracking-tight text-text-strong sm:text-4xl">
            Before you hand over a mailbox
        </h2>

        <dl class="mt-10 space-y-8">
            <?php foreach ($faq as $question => $answer): ?>
            <div>
                <dt class="text-lg font-medium text-text-strong"><?= $e($question) ?></dt>
                <dd class="mt-2 leading-relaxed text-text-muted"><?= $e($answer) ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
    </section>

    <!-- Closing -->
    <section class="mx-auto max-w-3xl px-4 pb-20">
        <div class="rounded-2xl border border-card-border bg-card p-8 sm:p-10">
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong sm:text-3xl">
                Stop writing the awkward one.
            </h2>
            <div class="mt-3">
                <?php $foundingTone = 'line'; require __DIR__ . '/partials/founding-note.php'; ?>
            </div>

            <div class="mt-6 max-w-lg">
                <?php
                $formId = 'signup-form-footer';
                $source = 'landing_footer';
                require __DIR__ . '/partials/signup-form.php';
                ?>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
