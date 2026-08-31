<?php
/**
 * The call to action on every marketing page.
 *
 * One field and one button. It is a real form with a real action, so a visitor
 * with no JavaScript gets the signup page with their address already filled in
 * rather than a dead button — the `email` query parameter on `/signup` is what
 * carries it across.
 *
 * It replaces the waitlist form, which asked people to queue for something they
 * can now simply use. The waitlist itself is untouched: the endpoints, the
 * confirmation links and the unsubscribe links are all still live, because they
 * are sitting in inboxes already.
 *
 * Expects nothing. Optional: $formId, $buttonLabel, $source.
 */
$formId = $formId ?? 'signup-form';
$buttonLabel = $buttonLabel ?? 'Create your account';
$source = $source ?? 'marketing';

$safeId = htmlspecialchars($formId, ENT_QUOTES, 'UTF-8');
?>
<form id="<?= $safeId ?>" method="GET" action="/signup" class="w-full">
    <input type="hidden" name="source" value="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">

    <div class="flex flex-col gap-3 sm:flex-row">
        <label class="sr-only" for="<?= $safeId ?>-email">Your email address</label>
        <input type="email"
               id="<?= $safeId ?>-email"
               name="email"
               required
               autocomplete="email"
               placeholder="you@yourstudio.com"
               class="form-input w-full flex-1">

        <button type="submit"
                class="shrink-0 whitespace-nowrap rounded-lg bg-brand px-6 py-3 font-semibold text-brand-contrast transition hover:bg-brand-hover">
            <?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <p class="mt-3 text-sm text-text-muted">
        No password, no card. We email you a six-digit code to confirm the address.
    </p>
</form>
