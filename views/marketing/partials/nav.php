<?php
/**
 * The public header.
 *
 * No menu button and no JavaScript: four links fit on a 375px screen if you
 * hide the longest one below 640px, and the footer carries the full set. A
 * hamburger here would be a script, a state, and a focus trap for four links.
 */
$current = $current ?? '';
?>
<header class="border-b border-card-border">
    <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-3 px-4 py-4"
         aria-label="Main">
        <a href="/" class="flex items-center gap-2 text-lg font-semibold text-text-strong">
            <span class="h-2.5 w-2.5 rounded-full bg-brand" aria-hidden="true"></span>
            Duely
        </a>

        <div class="flex items-center gap-4 text-sm">
            <a href="/how-it-works"
               class="hidden sm:inline text-text-muted transition hover:text-text-strong<?= $current === 'how-it-works' ? ' text-text-strong' : '' ?>">
                How it works
            </a>
            <a href="/pricing"
               class="text-text-muted transition hover:text-text-strong<?= $current === 'pricing' ? ' text-text-strong' : '' ?>">
                Pricing
            </a>
            <a href="/login" class="text-text-muted transition hover:text-text-strong">Sign in</a>
            <a href="/#waitlist"
               class="rounded-lg bg-brand px-3 py-1.5 font-medium text-brand-contrast transition hover:bg-brand-hover">
                Join the waitlist
            </a>
        </div>
    </nav>
</header>
