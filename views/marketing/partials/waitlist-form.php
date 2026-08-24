<?php
/**
 * The waitlist form.
 *
 * A real form with a real action, so it works before — or without — the script
 * that upgrades it. `landing_path` is what the server uses to send a
 * no-JavaScript submission back to the page it came from, and it is checked
 * against a fixed list there rather than trusted.
 *
 * Expects: $source, $landingPath. Optional: $formId.
 */
$source = $source ?? 'landing';
$landingPath = $landingPath ?? '/';
$formId = $formId ?? 'waitlist-form-' . $source;

$utm = [];
foreach (\Keel\App\Services\WaitlistService::UTM_KEYS as $utmKey) {
    $value = trim((string) ($_GET[$utmKey] ?? ''));

    if ($value !== '') {
        $utm[$utmKey] = mb_substr($value, 0, 128);
    }
}

// The outcome of a no-JavaScript submission comes back as a state, and the
// sentence is chosen here. Echoing a message out of the query string would let
// anyone hand out a link that makes this page say whatever they like.
$flashMessages = [
    'sent' => 'Check your inbox — we have sent you a link to confirm.',
    'invalid' => 'That does not look like an email address.',
    'error' => 'We could not send the confirmation email just now. Try again in a moment.',
];
$flashState = (string) ($_GET['waitlist'] ?? '');
$flashMessage = $flashMessages[$flashState] ?? '';

$safeId = htmlspecialchars($formId, ENT_QUOTES, 'UTF-8');
?>
<form id="<?= $safeId ?>"
      method="POST"
      action="/api/waitlist"
      data-waitlist-form
      class="w-full">
    <?= \Keel\Core\Csrf::field() ?>
    <input type="hidden" name="source" value="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="landing_path" value="<?= htmlspecialchars($landingPath, ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach ($utm as $utmKey => $utmValue): ?>
    <input type="hidden" name="<?= htmlspecialchars($utmKey, ENT_QUOTES, 'UTF-8') ?>"
           value="<?= htmlspecialchars($utmValue, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>

    <!-- Not a real field. Off-screen and hidden from assistive technology, so
         it is left empty by anyone with eyes or a screen reader. -->
    <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
        <label for="<?= $safeId ?>-company">Company</label>
        <input type="text" id="<?= $safeId ?>-company" name="company" tabindex="-1" autocomplete="off">
    </div>

    <div class="flex flex-col gap-2 sm:flex-row">
        <label class="sr-only" for="<?= $safeId ?>-email">Email address</label>
        <input type="email"
               id="<?= $safeId ?>-email"
               name="email"
               required
               autocomplete="email"
               inputmode="email"
               placeholder="you@yourstudio.com"
               class="w-full min-w-0 flex-1 rounded-lg border border-input-border bg-card px-4 py-3 text-base text-text placeholder:text-text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/40">
        <button type="submit"
                class="shrink-0 rounded-lg bg-brand px-5 py-3 text-base font-semibold text-brand-contrast transition hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/50">
            Join the waitlist
        </button>
    </div>

    <p class="mt-2 text-sm text-text-muted">
        We email you once to confirm, then only when your place is ready.
    </p>

    <p data-waitlist-message
       role="status"
       aria-live="polite"
       class="mt-2 text-sm <?= $flashState === 'sent' ? 'text-brand' : 'text-danger-text' ?><?= $flashMessage === '' ? ' hidden' : '' ?>">
        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
    </p>
</form>
