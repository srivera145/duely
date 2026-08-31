<?php
/**
 * Getting paid from the reminder — the optional half of the product.
 *
 * ---------------------------------------------------------------------------
 * GATED on STRIPE_CONNECT_CLIENT_ID. If this deployment cannot connect a Stripe
 * account, the section does not render at all. A marketing page promising a
 * feature the installation cannot deliver is worse than one that says nothing:
 * the first is a lie the visitor discovers after signing up.
 * ---------------------------------------------------------------------------
 *
 * What this copy may and may not say, and why each line is worded the way it is:
 *
 *   **No percentage, ever.** Stripe's rate varies by country and by account, so
 *   any figure printed here becomes wrong without anybody editing the page —
 *   and a stale fee quote on a pricing page is the kind of wrong that ends up
 *   in a complaint. The page says whose rate it is and stops.
 *
 *   **"Processor" and "facilitator" are absent by design.** Both carry
 *   regulatory meaning, and neither is true of Duely. So is any suggestion that
 *   Duely holds, receives, settles or handles funds: the money moves from the
 *   client to the user's own Stripe account, and Duely is told that it happened.
 *
 *   **Merchant of record is framed as control, not liability.** It is their
 *   account, their payouts, their relationship with the client — which is the
 *   accurate framing and also the appealing one, and those coinciding is why
 *   this feature is worth talking about at all.
 *
 *   **Optional is said out loud, on every page that mentions it.** Plenty of
 *   people will paste a PayPal link or take a bank transfer, and the page must
 *   not imply Duely-collected payment is the only way through.
 *
 * Optional: $paymentsTone — 'section' | 'compact'.
 */

// The gate. Not a feature flag to be flipped for a demo -- if the credential is
// absent, connecting a Stripe account is impossible on this deployment.
if (trim((string) \Keel\Core\Env::get('STRIPE_CONNECT_CLIENT_ID', '')) === '') {
    return;
}

$paymentsTone = $paymentsTone ?? 'section';
?>
<?php if ($paymentsTone === 'compact'): ?>
<div class="rounded-xl border border-card-border bg-card p-6">
    <h3 class="text-lg font-semibold text-text-strong">Let them pay from the reminder</h3>
    <p class="mt-3 leading-relaxed text-text-muted">
        Connect your own Stripe account and reminders can carry a pay button.
        <strong class="text-text">Duely adds no fee of its own</strong> &mdash; your client pays
        Stripe's processing fee at your own Stripe rate, and the money goes directly into your
        account.
    </p>
    <p class="mt-3 leading-relaxed text-text-muted">
        Optional, and off unless you turn it on. Reminders work exactly the same without it.
    </p>
</div>

<?php else: ?>
<section class="border-t border-card-border">
    <div class="mx-auto max-w-5xl px-4 py-16 sm:py-20">
        <p class="text-sm font-medium uppercase tracking-wide text-text-muted">Optional</p>

        <h2 class="mt-2 text-balance text-3xl font-semibold tracking-tight text-text-strong sm:text-4xl">
            They can pay from the reminder itself
        </h2>

        <p class="mt-4 max-w-2xl text-lg leading-relaxed text-text-muted">
            Connect your own Stripe account and Duely puts a pay button in the emails it sends.
            The client reads the reminder, clicks, and pays &mdash; without finding your bank
            details or waiting for you to send them.
        </p>

        <div class="mt-10 grid gap-6 sm:grid-cols-2">
            <div class="rounded-xl border border-card-border bg-card p-6">
                <h3 class="font-semibold text-text-strong">Duely adds no fee of its own</h3>
                <p class="mt-2 leading-relaxed text-text-muted">
                    Not a percentage, not a per-invoice charge, nothing. You pay for Duely and that
                    is the whole of what Duely costs.
                </p>
                <p class="mt-2 leading-relaxed text-text-muted">
                    <!--
                        No number here on purpose. Stripe's rate depends on the
                        account and the country, and a figure printed on this
                        page would go stale without anybody touching it.
                    -->
                    Your client pays Stripe's processing fee at your own Stripe rate, the same as if
                    you had sent them a Stripe link yourself.
                </p>
            </div>

            <div class="rounded-xl border border-card-border bg-card p-6">
                <h3 class="font-semibold text-text-strong">The money is yours from the moment it moves</h3>
                <p class="mt-2 leading-relaxed text-text-muted">
                    It goes directly from your client into your own Stripe account. It never passes
                    through Duely.
                </p>
                <p class="mt-2 leading-relaxed text-text-muted">
                    You stay the merchant of record: your account, your payouts, your agreement with
                    Stripe, your relationship with the client. Duely is told that a payment
                    succeeded, which is what marks the invoice paid and stops the chase.
                </p>
            </div>
        </div>

        <p class="mt-8 max-w-2xl leading-relaxed text-text-muted">
            All of it is optional and off unless you turn it on. Reminders work exactly the same
            without it &mdash; paste your own payment link, take a bank transfer, or carry on
            however you are paid today.
        </p>
    </div>
</section>
<?php endif; ?>
