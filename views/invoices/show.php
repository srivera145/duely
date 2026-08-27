<?php
/**
 * Duely — invoice timeline.
 *
 * The story of one invoice: what we sent, what came back, and where it ended
 * up. Sent messages carry their full rendered body behind a disclosure, so the
 * user never has to open their sent folder to see what went out in their name.
 */
$invoice = $invoice ?? [];
$chase = $chase ?? null;
$rail = $rail ?? [];
$events = $events ?? [];
$sequences = $sequences ?? [];

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents, string $currency): string => \Keel\App\Services\MoneyParser::format($cents, $currency);

$daysOverdue = \Keel\App\Models\Invoice::daysOverdue($invoice);
$isOpen = $invoice['status'] === 'open';

$eventStyles = [
    'created' => ['dot' => 'bg-text-muted', 'icon' => '+'],
    'chase_started' => ['dot' => 'bg-brand', 'icon' => '→'],
    'message' => ['dot' => 'bg-brand', 'icon' => '↑'],
    'reply' => ['dot' => 'bg-success', 'icon' => '↓'],
    'paused' => ['dot' => 'bg-tone-firm', 'icon' => '‖'],
    'paid' => ['dot' => 'bg-success', 'icon' => '✓'],
    // Money arriving is not the same as an invoice being settled, so a part
    // payment gets the attention colour rather than the success one.
    'payment' => ['dot' => 'bg-success', 'icon' => '$'],
    'void' => ['dot' => 'bg-text-muted', 'icon' => '×'],
];
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-screen bg-surface text-text">
    <div class="mx-auto max-w-4xl px-4 py-10" data-invoice-id="<?= (int) $invoice['id'] ?>">

        <?php
        ob_start(); ?>
                <p class="mt-2 text-sm text-text-muted">
                    <?= $e($money((int) $invoice['amount_cents'], (string) $invoice['currency'])) ?>
                    · <?= $e($invoice['client_name']) ?>
                    &lt;<?= $e($invoice['client_email']) ?>&gt;
                    · due <?= $e($invoice['due_date']) ?>
                </p>
                <?php if ($isOpen && $daysOverdue > 0): ?>
                <p class="mt-1 text-sm <?= \Keel\App\Services\ToneRamp::text(\Keel\App\Services\ToneRamp::forDaysOverdue((int) $daysOverdue)) ?>">
                    <?= $e(\Keel\App\Services\RelativeTime::overdueLabel($daysOverdue)) ?>
                </p>
                <?php endif; ?>
        <?php $pageSubtitle = ob_get_clean();
        ob_start(); ?>
            <div class="flex flex-wrap gap-2">
                <a href="/invoices/<?= (int) $invoice['id'] ?>/edit" class="btn btn-secondary border border-card-border btn-sm">Edit</a>
                <?php if ($isOpen): ?>
                <button type="button" class="btn btn-primary btn-sm" data-mark-paid="<?= (int) $invoice['id'] ?>">
                    Mark paid
                </button>
                <?php endif; ?>
            </div>
        <?php $pageActions = ob_get_clean();
        $pageTitle = $e($invoice['number']);
        $pageEyebrow = 'Invoices';
        $pageEyebrowHref = '/invoices';
        require __DIR__ . '/../partials/app-nav.php';
        ?>

        <!-- Progress rail. Built from the sequence's own offsets, so a tenant
             who edits their ladder sees their ladder rather than ours. -->
        <?php if ($rail !== []): ?>
        <section class="mb-8 rounded-xl border border-card-border bg-card p-6">
            <div class="flex items-start justify-between gap-2">
                <?php foreach ($rail as $index => $rung): ?>
                <?php
                $tone = (string) ($rung['tone'] ?? '');
                [$dotClass, $labelClass] = match ($rung['state']) {
                    'sent' => [\Keel\App\Services\ToneRamp::dotFilled($tone), 'text-text-strong'],
                    'due' => [\Keel\App\Services\ToneRamp::dotDue($tone), \Keel\App\Services\ToneRamp::text($tone)],
                    'cancelled' => ['bg-surface-muted border-card-border', 'text-text-muted line-through'],
                    default => ['bg-surface-muted border-card-border', 'text-text-muted'],
                };
                ?>
                <div class="flex flex-1 flex-col items-center text-center">
                    <div class="flex w-full items-center">
                        <div class="h-0.5 flex-1 <?= $index === 0 ? 'bg-transparent' : ($rung['state'] === 'sent' ? \Keel\App\Services\ToneRamp::line($tone) : 'bg-card-border') ?>"></div>
                        <div class="mx-2 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 <?= $dotClass ?>">
                            <?php if ($rung['state'] === 'sent'): ?>
                            <span class="text-xs font-bold text-brand-contrast">✓</span>
                            <?php else: ?>
                            <span class="text-xs text-text-muted"><?= (int) $rung['position'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="h-0.5 flex-1 <?= $index === count($rail) - 1 ? 'bg-transparent' : 'bg-card-border' ?>"></div>
                    </div>
                    <p class="mt-2 text-xs font-medium <?= $labelClass ?>"><?= $e($rung['label']) ?></p>
                    <p class="text-xs capitalize <?= \Keel\App\Services\ToneRamp::text($tone) ?>"><?= $e($rung['tone']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Chase controls -->
        <?php if ($chase !== null): ?>
        <section class="mb-8 rounded-xl border border-card-border bg-card p-5" data-chase-id="<?= (int) $chase['id'] ?>">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-text-muted">Reminders</p>
                    <p class="font-medium text-text-strong" data-chase-status>
                        <?= $e(ucfirst((string) $chase['status'])) ?>
                        <?php if (!empty($chase['paused_reason'])): ?>
                        <span class="text-text-muted">— <?= $e(str_replace('_', ' ', (string) $chase['paused_reason'])) ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($chase['next_send_at'])): ?>
                    <p class="mt-1 text-xs text-text-muted">
                        Next reminder
                        <?= $e(\Keel\App\Services\RelativeTime::phrase(\Keel\App\Services\Clock::fromDatabase($chase['next_send_at']))) ?>
                    </p>
                    <?php endif; ?>

                    <?php
                    // Whether the next reminder carries a pay button, and which
                    // link. A user should never have to open their client's
                    // inbox to find out what Duely sent on their behalf.
                    $plan = $paymentPlan ?? ['will_send' => false, 'kind' => 'none', 'url' => null, 'reason' => ''];
                    $planLine = match (true) {
                        $plan['kind'] === 'manual' => 'Carries your own payment link',
                        $plan['kind'] === 'generated' => 'Carries a Duely pay button',
                        $plan['kind'] === 'pending' => 'Will carry a Duely pay button',
                        $plan['reason'] === 'invoice_none' => 'No pay button — you turned it off for this invoice',
                        $plan['reason'] === 'workspace_never' => 'No pay button — Duely pay buttons are off for this workspace',
                        $plan['reason'] === 'workspace_manual_only' => 'No pay button — this workspace only uses links you add yourself',
                        $plan['reason'] === 'charges_disabled' => 'No pay button — Stripe is not letting this account charge yet',
                        $plan['reason'] === 'not_connected' => 'No pay button — no Stripe account connected',
                        default => 'No pay button',
                    };
                    ?>
                    <p class="mt-1 text-xs <?= $plan['will_send'] ? 'text-text' : 'text-text-muted' ?>">
                        <?= $e($planLine) ?>
                        <?php if ($plan['url'] !== null): ?>
                        &mdash;
                        <a href="<?= $e($plan['url']) ?>" rel="noopener noreferrer" target="_blank"
                           class="text-brand hover:underline break-all"><?= $e($plan['url']) ?></a>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php if (in_array($chase['status'], ['scheduled', 'active'], true)): ?>
                    <button type="button" class="btn btn-sm border border-card-border"
                            data-chase-action="send-now" data-chase-id="<?= (int) $chase['id'] ?>">Send next now</button>
                    <button type="button" class="btn btn-sm border border-card-border"
                            data-chase-action="pause" data-chase-id="<?= (int) $chase['id'] ?>">Pause</button>
                    <?php elseif ($chase['status'] === 'paused'): ?>
                    <button type="button" class="btn btn-sm btn-primary"
                            data-chase-action="resume" data-chase-id="<?= (int) $chase['id'] ?>">Resume</button>
                    <?php endif; ?>
                    <?php if (!in_array($chase['status'], ['stopped', 'completed'], true)): ?>
                    <button type="button" class="btn btn-sm text-danger-text hover:underline"
                            data-chase-action="stop" data-chase-id="<?= (int) $chase['id'] ?>">Stop</button>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php elseif ($isOpen): ?>
        <section class="mb-8 rounded-xl border border-card-border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-medium text-text-strong">Not being chased</p>
                    <p class="mt-1 text-sm text-text-muted">Duely is not sending reminders for this invoice yet.</p>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-start-chase="<?= (int) $invoice['id'] ?>">
                    Start chasing
                </button>
            </div>
        </section>
        <?php endif; ?>

        <!-- Timeline -->
        <section>
            <h2 class="mb-4 text-lg font-semibold text-text-strong">Activity</h2>

            <ol class="relative space-y-1 border-l border-card-border pl-6">
                <?php foreach ($events as $index => $event): ?>
                <?php
                $style = $eventStyles[$event['type']] ?? $eventStyles['created'];

                if ($event['type'] === 'payment' && ($event['outcome'] ?? '') === 'partial') {
                    $style = ['dot' => 'bg-tone-firm', 'icon' => '!'];
                }
                ?>
                <li class="relative pb-6">
                    <span class="absolute -left-[31px] flex h-5 w-5 items-center justify-center rounded-full <?= $style['dot'] ?> text-[10px] font-bold text-surface">
                        <?= $e($style['icon']) ?>
                    </span>

                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-medium text-text-strong"><?= $e($event['title']) ?></p>
                        <time class="text-xs text-text-muted"><?= $e(
                            \Keel\App\Services\Timezones::renderStored($event['at'], $timezone) ?? $event['at']
                        ) ?></time>
                    </div>

                    <?php if (!empty($event['detail'])): ?>
                    <p class="mt-1 text-sm text-text-muted"><?= $e($event['detail']) ?></p>
                    <?php endif; ?>

                    <?php if ($event['type'] === 'message'): ?>
                        <?php if (!empty($event['failed_reason'])): ?>
                        <p class="mt-2 rounded-lg border border-danger-border bg-danger-soft p-2 text-xs text-danger-text">
                            <?= $e($event['failed_reason']) ?>
                        </p>
                        <?php endif; ?>

                        <?php if (!empty($event['body_text'])): ?>
                        <details class="mt-2 rounded-lg border border-card-border bg-surface-muted">
                            <summary class="cursor-pointer px-3 py-2 text-xs text-text-muted">
                                Show what was sent to <?= $e($event['to_email']) ?>
                            </summary>
                            <div class="border-t border-card-border p-3">
                                <p class="text-xs text-text-muted">Subject</p>
                                <p class="mb-3 text-sm text-text"><?= $e($event['detail']) ?></p>
                                <p class="text-xs text-text-muted">Message</p>
                                <pre class="mt-1 whitespace-pre-wrap font-sans text-sm text-text"><?= $e($event['body_text']) ?></pre>
                            </div>
                        </details>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($event['type'] === 'payment' && ($event['outcome'] ?? '') === 'partial'): ?>
                    <div class="mt-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm">
                        <p class="font-semibold text-amber-400">
                            <?= $e($event['outstanding']) ?> is still outstanding
                        </p>
                        <p class="mt-1 text-text-muted">
                            The invoice is still open and the reminders have not stopped, because Duely cannot
                            tell whether the rest is coming. The next reminder asks for the full amount. Mark
                            the invoice paid or pause the chase if that is not what you want.
                        </p>
                    </div>
                    <?php endif; ?>

                    <?php if ($event['type'] === 'reply' && !empty($event['snippet'])): ?>
                    <blockquote class="mt-2 rounded-lg border-l-2 border-success bg-success-soft px-3 py-2 text-sm text-text">
                        <?= $e($event['snippet']) ?>
                        <footer class="mt-1 text-xs text-text-muted">— <?= $e($event['from_email']) ?></footer>
                    </blockquote>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <div id="dashboard-error" class="mt-4 hidden rounded-lg border border-danger-border bg-danger-soft p-3 text-sm text-danger-text"></div>
    </div>

    <div id="undo-toast"
         class="fixed bottom-6 left-1/2 hidden -translate-x-1/2 items-center gap-4 rounded-xl border border-card-border bg-card px-5 py-3 shadow-lg">
        <span class="text-sm text-text" data-undo-message></span>
        <button type="button" class="text-sm font-semibold text-brand hover:underline" data-undo-button>
            Undo (<span data-undo-countdown>30</span>)
        </button>
    </div>
</body>
</html>
