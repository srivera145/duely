<?php
/**
 * The public header.
 *
 * No menu button: four links fit on a 375px screen if you hide the longest one
 * below 640px, and the footer carries the full set. A hamburger here would be a
 * script, a state, and a focus trap for four links.
 *
 * The theme toggle is the one interactive control, and it is a real button in
 * the markup rather than one the script injects — an injected control appears a
 * frame late and shifts the row it lands in.
 */
$current = $current ?? '';
?>
<header class="border-b border-card-border">
    <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-3 px-4 py-4"
         aria-label="Main">
        <?php
        // The mark plus the word, not the stacked lockup: the lockup's tagline
        // stops resolving below about 56px and this bar is 24px of content.
        $variant = 'mark';
        $size = 'sm';
        $link = true;
        $wordmark = true;
        require __DIR__ . '/../../partials/logo.php';
        ?>

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
            <a href="/signup"
               class="rounded-lg bg-brand px-3 py-1.5 font-medium text-brand-contrast transition hover:bg-brand-hover">
                Create your account
            </a>
            <button type="button"
                    class="theme-toggle-button h-9 w-9"
                    data-theme-toggle
                    aria-label="Switch between light and dark">
                <span data-theme-toggle-icon aria-hidden="true"></span>
            </button>
        </div>
    </nav>
</header>
