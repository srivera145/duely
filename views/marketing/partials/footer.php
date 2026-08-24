<?php
/**
 * The public footer.
 *
 * Carries the full link set, including the one the header hides below 640px,
 * so nothing is unreachable on a phone.
 */
$year = (int) date('Y');
?>
<footer class="mt-20 border-t border-card-border">
    <div class="mx-auto max-w-6xl px-4 py-12">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <a href="/" class="flex items-center gap-2 text-lg font-semibold text-text-strong">
                    <span class="h-2.5 w-2.5 rounded-full bg-brand" aria-hidden="true"></span>
                    Duely
                </a>
                <p class="mt-3 max-w-sm text-sm leading-relaxed text-text-muted">
                    Follow-ups for overdue invoices, sent from your own inbox — and stopped the
                    moment your client replies.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-text-strong">Product</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="/how-it-works" class="text-text-muted transition hover:text-text-strong">How it works</a></li>
                    <li><a href="/pricing" class="text-text-muted transition hover:text-text-strong">Pricing</a></li>
                    <li><a href="/login" class="text-text-muted transition hover:text-text-strong">Sign in</a></li>
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-text-strong">Legal</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="/privacy" class="text-text-muted transition hover:text-text-strong">Privacy &amp; your mailbox</a></li>
                    <li><a href="/terms" class="text-text-muted transition hover:text-text-strong">Terms</a></li>
                </ul>
            </div>
        </div>

        <p class="mt-10 border-t border-card-border pt-6 text-sm text-text-muted">
            &copy; <?= $year ?> Duely. Reminders are sent through your mailbox, never ours.
        </p>
    </div>
</footer>
