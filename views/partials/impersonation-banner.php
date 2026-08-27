<?php
/**
 * The banner on every page of an impersonated session.
 *
 * Not a toast, not dismissible, and not subtle. The failure it prevents is an
 * operator forgetting which session they are in and acting inside somebody's
 * account believing it is their own — which is exactly the moment the audit
 * trail stops matching what a human thinks happened.
 *
 * Rendered from `partials/head.php` so it appears on every authenticated page
 * without each view opting in. A banner a page can forget to render is a banner
 * that will be missing from the one page where it mattered.
 */

$impersonation = (new \Keel\App\Services\ImpersonationService())->current();

if ($impersonation === null) {
    return;
}

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$expiresAt = \Keel\App\Services\Clock::fromDatabase((string) $impersonation['expires_at']);
$minutesLeft = max(0, (int) ceil(($expiresAt->getTimestamp() - \Keel\App\Services\Clock::now()->getTimestamp()) / 60));
?>
<div class="sticky top-0 z-50 border-b-2 border-amber-400 bg-amber-500 px-4 py-3 text-amber-950">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span aria-hidden="true" class="text-lg leading-none">&#9888;</span>
            <p class="text-sm font-semibold">
                Support session &mdash; you are viewing Duely as
                <span class="font-bold"><?= $e($impersonation['target_email']) ?></span>.
                <span class="font-normal">
                    Read-only, <?= $e($minutesLeft) ?> minute<?= $minutesLeft === 1 ? '' : 's' ?> left.
                </span>
            </p>
        </div>

        <!--
            One click out, in the banner itself. Making somebody navigate
            somewhere to leave is how a session gets left open.
        -->
        <form method="post" action="/impersonation/stop">
            <?= \Keel\Core\Csrf::field() ?>
            <button type="submit"
                    class="rounded-md bg-amber-950 px-3 py-1.5 text-sm font-semibold text-amber-50 transition hover:bg-amber-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-950">
                End support session
            </button>
        </form>
    </div>
</div>
