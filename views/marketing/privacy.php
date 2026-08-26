<?php
/**
 * Privacy — and specifically, what Duely does with a mailbox.
 *
 * This page is read by someone with their hand on an app password, deciding
 * whether to hand it over. It is written to be read in that state: what is
 * touched, what is stored, what is never done, and how to check.
 *
 * Every claim here describes behaviour that is actually implemented and
 * tested. If the product changes, this page changes with it.
 */
$updatedOn = $updatedOn ?? '2026-08-24';
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$updatedLabel = date('j F Y', strtotime($updatedOn));
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text antialiased">
<?php $current = 'privacy'; require __DIR__ . '/partials/nav.php'; ?>

<main>
    <section class="mx-auto max-w-3xl px-4 pb-10 pt-12 sm:pt-16">
        <h1 class="text-4xl font-semibold leading-tight tracking-tight text-text-strong sm:text-5xl">
            What Duely does with your mailbox
        </h1>
        <p class="mt-6 text-lg leading-relaxed text-text-muted">
            You are about to give a piece of software access to your email. That deserves a straight
            answer rather than a policy, so here is the whole of it in one page.
        </p>
        <p class="mt-4 text-sm text-text-muted">Last updated <?= $e($updatedLabel) ?>.</p>
    </section>

    <!-- The four sentences that matter -->
    <section class="mx-auto max-w-3xl px-4 pb-14">
        <div class="rounded-2xl border border-brand/40 bg-brand/5 p-6 sm:p-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-brand">The short version</h2>
            <ul class="mt-5 space-y-4">
                <?php
                $summary = [
                    'Duely reads your mailbox only to match replies to invoices.' => 'That is the '
                        . 'entire reason it has access. It is not scanning for anything else, and '
                        . 'there is nothing else it does with what it reads.',
                    'It stores a snippet, never the message.' => 'Roughly the first three hundred '
                        . 'characters of a reply, so you can see what was said without opening your '
                        . 'inbox. Message bodies are never written to our database.',
                    'It never marks anything read, deletes, or moves anything.' => 'The connection '
                        . 'is opened read-only and messages are fetched without touching their read '
                        . 'flag. Your unread count is exactly as you left it.',
                    'Your mailbox password is encrypted at rest.' => 'AES-256-GCM, with the key held '
                        . 'in the server environment rather than in the database. A stolen copy of '
                        . 'the database does not contain a usable credential.',
                ];
                foreach ($summary as $heading => $body):
                ?>
                <li class="flex gap-4">
                    <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
                    <div>
                        <h3 class="font-medium text-text-strong"><?= $e($heading) ?></h3>
                        <p class="mt-1 leading-relaxed text-text-muted"><?= $e($body) ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <!-- The detail -->
    <section class="mx-auto max-w-3xl space-y-14 px-4 pb-16">

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Sending</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                Duely connects to your outgoing mail server and sends the reminder as you. The
                message comes from your address, appears in your sent items where your provider
                keeps a copy, and is threaded onto the original invoice email so your client sees
                one conversation.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                We keep the subject line and body of the reminders <em>we</em> sent, because you need
                to be able to see what went out in your name. We do not keep anything else that
                passes through the connection.
            </p>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Reading</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                Every few minutes, Duely checks your inbox for new messages since the last time it
                looked. For each new message it reads the headers &mdash; who it is from, what it is
                replying to &mdash; and matches it against the chases you have running, in this
                order:
            </p>
            <ol class="mt-5 space-y-3">
                <?php
                $matching = [
                    'The reply headers' => 'If the message is a reply to something Duely sent, the '
                        . 'headers say so outright.',
                    'The provider thread' => 'If your provider groups it into the same conversation, '
                        . 'that is used next.',
                    'The sender, recently' => 'Failing both, a message from a client you are chasing, '
                        . 'received within the last sixty days.',
                ];
                foreach ($matching as $heading => $body):
                ?>
                <li class="flex gap-4 text-text-muted">
                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-text-muted" aria-hidden="true"></span>
                    <span><strong class="font-medium text-text-strong"><?= $e($heading) ?>.</strong> <?= $e($body) ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
            <p class="mt-5 leading-relaxed text-text-muted">
                Subject lines are never matched on their own &mdash; too many unrelated emails share
                a subject, and a wrong match would stop the wrong chase.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                Messages that match nothing are ignored and nothing about them is stored. Messages
                that do match produce one record: which chase it belongs to, whether it looked like
                a person, an autoresponder, or a bounce, when it arrived, and a snippet of about
                three hundred characters.
            </p>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">What Duely never does</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                The mailbox connection is opened in read-only mode, and messages are fetched with the
                peek instruction that leaves the read flag alone. Duely has no code path that issues
                a delete, a move, a copy, or a flag change &mdash; and there is a test that connects
                to a fake mail server, records every command Duely sends, and fails the build if any
                of those appears.
            </p>
            <ul class="mt-5 grid gap-3 sm:grid-cols-2">
                <?php
                $nevers = [
                    'Mark a message as read',
                    'Delete or archive anything',
                    'Move anything between folders',
                    'Send anything you did not schedule',
                    'Store message bodies',
                    'Read mail unrelated to a chase',
                ];
                foreach ($nevers as $never):
                ?>
                <li class="flex items-center gap-3 rounded-lg border border-card-border bg-card px-4 py-3 text-text-muted">
                    <span class="text-danger-text" aria-hidden="true">&times;</span>
                    <?= $e($never) ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Your credentials</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                Mailbox passwords are encrypted with AES-256-GCM before they are written. The
                encryption key lives in the server's environment, never in the database, so a copy
                of the database on its own is not enough to decrypt anything. Each stored value
                carries its own initialisation vector and authentication tag, and a value that has
                been tampered with fails to decrypt rather than being used.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                A password is decrypted in memory at the moment of sending or polling and discarded.
                It is never written to a log, never returned to your browser, and never rendered
                into a page &mdash; the settings screen shows a masked placeholder and only writes a
                new value if you type one.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                We ask for an app password rather than your account password wherever your provider
                offers one, because you can revoke an app password without changing anything else.
                Revoking it removes Duely's access immediately, from your side, without asking us.
            </p>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Your invoices and clients</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                Names, email addresses, invoice numbers, amounts and due dates &mdash; whatever you
                import or type. It is stored so Duely can write the reminders and know when to stop,
                and it is scoped to your workspace: every query is filtered by it, and there is no
                screen or endpoint that returns another workspace's data.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                Duely never touches money. Payments happen the way they always have, directly
                between you and your client. Marking an invoice paid tells Duely to stop; it does
                not tell us anything about how you were paid.
            </p>
        </article>

        <article id="ai">
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">The writing assistant</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                If you ask Duely to help reword a reminder, the template is sent to Anthropic's API
                to be rewritten. Real client names, real invoice numbers and real amounts are
                stripped out first and replaced with the placeholder tags Duely fills in at send
                time, so what leaves the server is a form letter rather than anybody's data. Duely
                shows you what it removed.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                Nothing is sent anywhere unless you press the button, and the result is shown to you
                as a suggestion &mdash; it is never saved into a live sequence on its own.
            </p>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Reading an invoice you upload</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                Duely can read an invoice off a PDF or a photo so you do not have to type it in.
                This one works differently from the writing assistant above, and the difference
                matters enough to spell out.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                To read the document, Duely sends <strong class="text-text-strong">the document
                itself</strong> to Anthropic. Whatever is printed on it goes too &mdash; your
                client's name, the amount, their address if it is on there. Nothing is stripped
                first, because the details are the thing being read.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                So it is off until you turn it on. A workspace has to switch it on deliberately,
                after being told plainly what is sent, and can switch it off again at any time.
                If you never use it, no invoice document ever leaves this server.
            </p>
            <ul class="mt-5 space-y-3">
                <?php
                $extractionFacts = [
                    'The file is deleted as soon as it is read.' => 'It is held only for the
                        seconds it takes to send, and never written to disk here.',
                    'Nothing is saved from it automatically.' => 'What comes back is filled into
                        the invoice form for you to check. An invoice exists only once you save it.',
                    'Anthropic does not train on it.' => 'Duely uses the paid API, where inputs
                        are not used to train models.',
                    'It counts against the same daily limit.' => 'Reading documents and rewriting
                        reminders share one budget of twenty calls a workspace a day.',
                ];
                foreach ($extractionFacts as $heading => $body):
                ?>
                <li class="flex gap-4">
                    <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
                    <div>
                        <h3 class="font-medium text-text-strong"><?= $e($heading) ?></h3>
                        <p class="mt-1 leading-relaxed text-text-muted"><?= $e($body) ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Billing</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                Card details are handled entirely by Stripe and never reach our servers. We store the
                identifiers Stripe gives us &mdash; a customer id and a subscription id &mdash; and
                what plan they entitle you to.
            </p>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">The waitlist</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                If you join the waitlist we store your email address, which form you used, any
                campaign parameters that were in the link, and a one-way hash of your IP address so
                we can spot a machine submitting hundreds of addresses. The raw address is not kept.
            </p>
            <p class="mt-4 leading-relaxed text-text-muted">
                Nothing is added to the list until you click the confirmation link, so nobody can put
                your address on it but you. Every email we send from the list has an unsubscribe
                link, and unsubscribing is immediate.
            </p>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Deleting your account</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                Disconnecting a mailbox removes the stored credentials straight away. Deleting your
                workspace removes your invoices, clients, chases, message history and reply records
                &mdash; the database is set up so that they go with it rather than being left behind
                as orphans. Backups age out on their own schedule; ask and we will tell you where
                that stands.
            </p>
        </article>

        <article>
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Questions</h2>
            <p class="mt-4 leading-relaxed text-text-muted">
                If anything here is unclear, or you want to know something this page does not cover,
                ask before you connect anything. A question about what we do with your mailbox is
                always a fair question.
            </p>
        </article>
    </section>

    <section class="mx-auto max-w-3xl px-4 pb-20">
        <div class="rounded-2xl border border-card-border bg-card p-8 sm:p-10">
            <h2 class="text-2xl font-semibold tracking-tight text-text-strong">Still want in?</h2>
            <p class="mt-3 leading-relaxed text-text-muted">
                Join the waitlist. Reading this page first is exactly the right instinct.
            </p>
            <div id="waitlist" class="mt-6 max-w-lg scroll-mt-24">
                <?php
                $source = 'landing_footer';
                $landingPath = '/';
                $formId = 'waitlist-form-privacy';
                require __DIR__ . '/partials/waitlist-form.php';
                ?>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
