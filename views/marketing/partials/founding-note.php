<?php
/**
 * The founding offer, in words, in one place.
 *
 * Every marketing page renders this rather than writing its own sentence. Two
 * pages describing the same offer differently is how a promise turns into a
 * dispute, and the homepage and the pricing page were already drifting.
 *
 * Three states, and the middle one is the one worth being careful about. Under
 * ten places left, the copy says the number and stops. No timer, no "hurry", no
 * red text. The scarcity is real — there are fifty rows in a table — and a real
 * constraint does not need dressing up; dressing it up is what makes people
 * suspect it is not real.
 *
 * The "gone" state is a normal end, not a failure. It states standard pricing
 * and moves on, because a page that reads as apologetic about its own pricing
 * teaches the visitor that the price is a problem.
 *
 * Expects: $founding (from FoundingCounter::snapshot(), or null).
 * Optional: $foundingTone — 'badge' | 'line' | 'block'.
 */
$founding = $founding ?? null;
$foundingTone = $foundingTone ?? 'line';

// No number rather than a guess. The counter returns null only when the count
// cannot be read, and an invented one is worse than silence.
if ($founding === null) {
    return;
}

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$remaining = (int) $founding['remaining'];
$total = (int) $founding['total'];
$price = (string) ($founding['price'] ?? '$19.00');
$state = (string) ($founding['state'] ?? 'available');

// $19.00 reads as a price tag; $19 reads as a sentence.
$priceLabel = rtrim(rtrim($price, '0'), '.');

$headline = match ($state) {
    'gone' => 'The ' . $total . ' founding places have been taken',
    'few' => $remaining === 1
        ? 'One founding place left'
        : $remaining . ' founding places left',
    default => $remaining . ' of ' . $total . ' founding places left',
};

$sentence = $state === 'gone'
    // Not apologetic, and not a consolation prize. Standard pricing is the
    // product's actual price and the free plan is genuinely unlimited in time.
    ? 'Duely is open to everyone at standard pricing, and the free plan has no time limit.'
    : 'Signing up takes one and holds it for 30 days. Start a paid plan in that time and it is '
        . $priceLabel . ' a month for as long as you stay subscribed, whatever the price '
        . 'becomes later.';
?>
<?php if ($foundingTone === 'badge'): ?>
<p class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm <?= $state === 'gone'
    ? 'border-card-border bg-surface-muted text-text-muted'
    : 'border-brand/40 bg-brand/10 text-brand' ?>">
    <?php if ($state !== 'gone'): ?>
    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
    <?php endif; ?>
    <?= $e($headline) ?>
</p>

<?php elseif ($foundingTone === 'block'): ?>
<div class="rounded-xl border border-card-border bg-card p-5">
    <p class="font-semibold text-text-strong"><?= $e($headline) ?></p>
    <p class="mt-1 text-sm leading-relaxed text-text-muted"><?= $e($sentence) ?></p>
</div>

<?php else: ?>
<p class="text-sm leading-relaxed text-text-muted">
    <strong class="<?= $state === 'gone' ? 'text-text' : 'text-brand' ?>"><?= $e($headline) ?>.</strong>
    <?= $e($sentence) ?>
</p>
<?php endif; ?>
