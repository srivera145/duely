<?php
/**
 * Terms of service.
 *
 * Kept short and in the same voice as the rest of the site. Nothing here is a
 * substitute for a lawyer reading it before launch.
 */
$updatedOn = $updatedOn ?? '2026-08-24';
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$updatedLabel = date('j F Y', strtotime($updatedOn));

$sections = [
    'What Duely is' => [
        'Duely is a service that sends follow-up emails about your overdue invoices, through '
        . 'your own mailbox, on a schedule you control. It is a tool for writing and timing '
        . 'messages. It is not an accountant, a lawyer, or a debt collection agency, and nothing '
        . 'it sends is legal advice or a formal demand.',
    ],
    'Your account' => [
        'You need an email address to sign in. You are responsible for what is sent from your '
        . 'account, and for keeping access to it secure — Duely sends as you, so anyone who can '
        . 'sign in as you can send as you.',
        'You must be entitled to invoice the people you are chasing, and entitled to email them. '
        . 'Duely is for following up on real invoices you issued to real clients. It is not a '
        . 'tool for sending unsolicited email, and using it that way ends the account.',
    ],
    'Your mailbox' => [
        'By connecting a mailbox you authorise Duely to send messages from it on your behalf and '
        . 'to read it for the purpose of matching replies to your invoices. What that means in '
        . 'practice is set out in full on the privacy page, and those limits are part of these '
        . 'terms rather than separate from them.',
        'You can disconnect a mailbox at any time, and you can revoke the app password from your '
        . 'email provider without asking us. Either one stops Duely immediately.',
    ],
    'What you keep' => [
        'Your invoices, your clients, your wording and your relationships are yours. We claim no '
        . 'ownership of anything you put into Duely, and we do not sell or share it. We use it to '
        . 'run the service you asked for, and for nothing else.',
    ],
    'Payment' => [
        'Paid plans are billed monthly in advance through Stripe. You can cancel at any time and '
        . 'keep access until the end of the period you have paid for; we do not refund part '
        . 'months, and we do not bill you again after you cancel.',
        'Founding accounts — the first fifty that start paying — keep their price for as long as '
        . 'the subscription stays active. If the subscription is cancelled, the founding price '
        . 'goes with it.',
        'If a payment fails, we do not cut you off on the first attempt. Stripe retries; if it '
        . 'never succeeds, the account drops to the free plan and chases beyond the free limit '
        . 'are paused rather than deleted.',
    ],
    'What we do not promise' => [
        'Duely does not guarantee that you will be paid. It sends the messages you would have '
        . 'sent, at the times you would have meant to send them. Whether a client pays is between '
        . 'you and your client.',
        'We do not guarantee that email will be delivered. Mail passes through your provider and '
        . 'your client\'s, and either can delay, filter or reject it for reasons outside our '
        . 'control. Duely reports what it can see — sends, bounces, replies — and tells you when '
        . 'something has gone wrong.',
        'The service is provided as it is. We work hard to keep it running and correct, and we '
        . 'will tell you plainly when it is not, but we do not promise it will never be '
        . 'unavailable.',
    ],
    'Limits of liability' => [
        'To the extent the law allows, our liability for anything arising out of your use of '
        . 'Duely is limited to the amount you have paid us in the twelve months before the claim. '
        . 'We are not liable for invoices that go unpaid, for business lost, or for anything a '
        . 'client did or did not do in response to a message.',
    ],
    'Ending it' => [
        'You can stop using Duely and delete your workspace whenever you like. We can close an '
        . 'account that is being used to send unsolicited email, to harass someone, or in a way '
        . 'that puts other people\'s mail delivery at risk — and we will tell you why.',
    ],
    'Changes' => [
        'If these terms change in a way that matters, we will email you before it takes effect '
        . 'rather than quietly updating the date at the top of this page.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text antialiased">
<?php $current = 'terms'; require __DIR__ . '/partials/nav.php'; ?>

<main>
    <section class="mx-auto max-w-3xl px-4 pb-10 pt-12 sm:pt-16">
        <h1 class="text-4xl font-semibold leading-tight tracking-tight text-text-strong sm:text-5xl">
            Terms of service
        </h1>
        <p class="mt-6 text-lg leading-relaxed text-text-muted">
            The agreement between you and Duely, written to be read.
        </p>
        <p class="mt-4 text-sm text-text-muted">Last updated <?= $e($updatedLabel) ?>.</p>
    </section>

    <section class="mx-auto max-w-3xl space-y-12 px-4 pb-16">
        <?php foreach ($sections as $heading => $paragraphs): ?>
        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong"><?= $e($heading) ?></h2>
            <?php foreach ($paragraphs as $paragraph): ?>
            <p class="mt-4 leading-relaxed text-text-muted"><?= $e($paragraph) ?></p>
            <?php endforeach; ?>
        </article>
        <?php endforeach; ?>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Privacy</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                What Duely does with your mailbox is set out in full on the
                <a href="/privacy" class="text-brand underline underline-offset-4 hover:text-brand-hover">privacy page</a>,
                which forms part of these terms.
            </p>
        </article>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
