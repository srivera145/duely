<?php
/**
 * The hero visual: one invoice, mid-chase.
 *
 * HTML and CSS rather than an image, so it stays sharp on any screen, reads to
 * a screen reader, and can be edited when the copy changes.
 *
 * It is laid out to survive 375px: the rail is a three-column grid whose track
 * runs between the outer node centres (a sixth in from each edge) rather than
 * edge to edge, so the line never overshoots the first and last dots however
 * narrow the card gets.
 */
$steps = [
    ['day' => 3,  'label' => 'Polite nudge',  'state' => 'sent'],
    ['day' => 14, 'label' => 'Firmer note',   'state' => 'sent'],
    ['day' => 30, 'label' => 'Final message', 'state' => 'upcoming'],
];
?>
<figure class="w-full max-w-md rounded-2xl border border-card-border bg-card p-5 shadow-2xl sm:p-6">
    <figcaption class="sr-only">
        An invoice for $2,400 to Northwind Studio, eighteen days overdue. Duely has sent the day
        three and day fourteen reminders from the freelancer's own address; the day thirty message
        is scheduled and will not be sent if the client replies first.
    </figcaption>

    <!-- Invoice header -->
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-sm text-text-muted">Northwind Studio</p>
            <p class="mt-0.5 text-lg font-semibold text-text-strong">INV&#8209;2041</p>
        </div>
        <span class="shrink-0 rounded-full border border-amber-500/40 bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-400">
            18 days overdue
        </span>
    </div>

    <p class="mt-4 text-3xl font-semibold tracking-tight text-text-strong">$2,400.00</p>
    <p class="mt-1 text-sm text-text-muted">Due 6 August &middot; Net 14</p>

    <!-- The escalation rail -->
    <div class="relative mt-7">
        <!-- Track, drawn between the first and last node centres. -->
        <div class="absolute left-[16.6667%] right-[16.6667%] top-3 h-px bg-card-border" aria-hidden="true"></div>
        <!-- Progress: as far as the last message actually sent. -->
        <div class="absolute left-[16.6667%] top-3 h-px w-1/3 bg-brand" aria-hidden="true"></div>

        <ol class="relative grid grid-cols-3 gap-1">
            <?php foreach ($steps as $step): ?>
            <?php $sent = $step['state'] === 'sent'; ?>
            <li class="flex flex-col items-center text-center">
                <span class="flex h-6 w-6 items-center justify-center rounded-full border-2 text-[10px] font-bold <?=
                    $sent
                        ? 'border-brand bg-brand text-brand-contrast'
                        : 'border-card-border bg-card text-text-muted' ?>">
                    <?php if ($sent): ?>
                    <svg viewBox="0 0 12 12" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2.5 6.4 4.8 8.7 9.5 3.6" />
                    </svg>
                    <?php else: ?>
                    &middot;
                    <?php endif; ?>
                </span>
                <span class="mt-2 text-xs font-medium <?= $sent ? 'text-text-strong' : 'text-text-muted' ?>">
                    Day <?= (int) $step['day'] ?>
                </span>
                <span class="mt-0.5 text-[11px] leading-tight text-text-muted">
                    <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <!-- The message that actually went out -->
    <div class="mt-7 rounded-xl border border-card-border bg-surface-muted p-4">
        <div class="flex items-center justify-between gap-2 text-[11px] text-text-muted">
            <span class="truncate">
                From <span class="text-text-strong">you@lanternstudio.com</span>
            </span>
            <span class="shrink-0">Sent day 14</span>
        </div>

        <p class="mt-2.5 text-sm font-medium text-text-strong">
            Invoice INV&#8209;2041 &mdash; still showing as open
        </p>
        <p class="mt-1.5 text-[13px] leading-relaxed text-text-muted">
            Hi Ellie &mdash; following up on INV&#8209;2041 for $2,400, which was due on 6 August.
            Could you let me know when it&rsquo;s likely to go out? Happy to resend it if that helps.
        </p>
    </div>

    <p class="mt-4 flex items-start gap-2 text-xs leading-relaxed text-text-muted">
        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
        The day 30 message is scheduled. If Ellie replies, it never gets sent.
    </p>
</figure>
