<?php

namespace Keel\App\Services;

use Keel\App\Models\Invoice;
use Keel\Core\Database;
use Keel\Core\Env;
use Stripe\StripeClient;
use Throwable;

/**
 * A pay-this-invoice link, on the user's own Stripe account.
 *
 * Three rules shape everything here.
 *
 * **Lazy.** Links are made on first reminder, or when the user asks — never on
 * invoice creation. Most invoices are paid without a reminder ever going out,
 * and generating up front would leave thousands of unused objects in the user's
 * Stripe account for them to wonder about.
 *
 * **Theirs, not ours.** Every call passes `stripe_account`, so the link, the
 * price and the payment belong to the connected account. No platform fee is
 * ever set on any of it: Duely takes nothing on top.
 *
 * **The user's own link always wins.** A pasted PayPal or Wave URL is never
 * overwritten. The existing `payment_url` column and `{{invoice_url}}` tag are
 * untouched by this feature, which is why a manual link keeps working exactly
 * as before.
 */
class PaymentLinkService
{
    public function __construct(private readonly ConnectService $connect = new ConnectService())
    {
    }

    /**
     * The link to put in a reminder, making one if it is worth making.
     *
     * Returns null whenever there is nothing to offer — not connected, charges
     * disabled, invoice not open — and the reminder simply goes out without a
     * pay button, exactly as it does today.
     */
    public function linkFor(int $tenantId, array $invoice): ?string
    {
        $existing = trim((string) ($invoice['payment_url'] ?? ''));

        // Whatever the user typed stands. This check comes first on purpose:
        // no Stripe call, no overwrite, no argument.
        if ($existing !== '' && !(bool) ($invoice['payment_url_is_generated'] ?? false)) {
            return $existing;
        }

        if ($existing !== '') {
            return $existing;
        }

        $result = $this->generate($tenantId, $invoice);

        return $result['ok'] ? $result['url'] : null;
    }

    /**
     * Make a payment link for an invoice.
     *
     * @return array{ok:bool, url:?string, error:?string, reason:?string}
     */
    public function generate(int $tenantId, array $invoice): array
    {
        $status = $this->connect->status($tenantId);

        if (!$status['connected']) {
            return $this->no('not_connected', 'Connect a Stripe account first.');
        }

        if (!$status['charges_enabled']) {
            // A link that fails at checkout is worse than no link: the client
            // has already decided to pay and hit a wall doing it.
            return $this->no(
                'charges_disabled',
                'Stripe is not letting this account take payments yet. Finish verification in Stripe first.'
            );
        }

        if (($invoice['status'] ?? '') !== Invoice::STATUS_OPEN) {
            return $this->no('not_open', 'Only an open invoice can be paid.');
        }

        $existing = trim((string) ($invoice['payment_url'] ?? ''));

        if ($existing !== '' && !(bool) ($invoice['payment_url_is_generated'] ?? false)) {
            return $this->no('manual_link', 'This invoice already has a payment link you set yourself.');
        }

        if ($existing !== '') {
            return ['ok' => true, 'url' => $existing, 'error' => null, 'reason' => 'existing'];
        }

        $amount = (int) ($invoice['amount_cents'] ?? 0);

        if ($amount <= 0) {
            return $this->no('no_amount', 'That invoice has no amount to charge.');
        }

        try {
            $client = new StripeClient((string) Env::get('STRIPE_SECRET_KEY', ''));

            // Everything below runs *on the connected account*, which is what
            // makes the resulting objects and the money theirs.
            $options = ['stripe_account' => $status['account_id']];

            // Amount and currency come from the invoice record. Never from the
            // request: a link-creation endpoint that accepts an amount is a
            // charge-anything endpoint.
            $price = $client->prices->create([
                'unit_amount' => $amount,
                'currency' => strtolower((string) ($invoice['currency'] ?? 'usd')),
                'product_data' => [
                    'name' => 'Invoice ' . (string) ($invoice['number'] ?? ''),
                ],
            ], $options);

            $link = $client->paymentLinks->create([
                'line_items' => [[
                    'price' => $price->id,
                    'quantity' => 1,
                ]],
                // How the webhook finds its way home. Both halves matter: the
                // invoice says which row, the tenant says whose.
                'metadata' => [
                    'duely_invoice_id' => (string) $invoice['id'],
                    'duely_tenant_id' => (string) $tenantId,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'duely_invoice_id' => (string) $invoice['id'],
                        'duely_tenant_id' => (string) $tenantId,
                    ],
                ],
                // No platform fee parameter appears anywhere in this call.
                // Duely takes nothing on top of what the user collects.
            ], $options);
        } catch (Throwable $exception) {
            error_log('[Duely] Payment link creation failed: ' . $exception->getMessage());

            return $this->no('stripe_error', 'Stripe could not create a payment link just now.');
        }

        $url = (string) ($link->url ?? '');

        if ($url === '') {
            return $this->no('stripe_error', 'Stripe returned a link with no URL.');
        }

        $this->store($tenantId, (int) $invoice['id'], $url, (string) $link->id);

        return ['ok' => true, 'url' => $url, 'error' => null, 'reason' => 'created'];
    }

    // -------------------------------------------------------------- internals

    /**
     * Written straight to the column the template already reads, so
     * `{{invoice_url}}` renders it with no template change at all.
     *
     * The WHERE clause is belt and braces: even if a manual link were somehow
     * set between the check above and this write, it would not be clobbered.
     */
    private function store(int $tenantId, int $invoiceId, string $url, string $linkId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE invoices
             SET payment_url = ?, stripe_payment_link_id = ?, payment_url_is_generated = 1
             WHERE tenant_id = ? AND id = ?
               AND (payment_url IS NULL OR payment_url = "" OR payment_url_is_generated = 1)'
        );
        $statement->execute([$url, $linkId, $tenantId, $invoiceId]);
    }

    private function no(string $reason, string $message): array
    {
        return ['ok' => false, 'url' => null, 'error' => $message, 'reason' => $reason];
    }
}
