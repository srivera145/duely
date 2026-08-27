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
    /** Workspace defaults. */
    public const WORKSPACE_ALWAYS = 'always';
    public const WORKSPACE_MANUAL_ONLY = 'manual_only';
    public const WORKSPACE_NEVER = 'never';

    /** Per-invoice overrides. NULL in the column means DEFAULT. */
    public const INVOICE_DEFAULT = 'default';
    public const INVOICE_GENERATE = 'generate';
    public const INVOICE_NONE = 'none';

    public const WORKSPACE_MODES = [
        self::WORKSPACE_ALWAYS,
        self::WORKSPACE_MANUAL_ONLY,
        self::WORKSPACE_NEVER,
    ];

    public const INVOICE_MODES = [
        self::INVOICE_DEFAULT,
        self::INVOICE_GENERATE,
        self::INVOICE_NONE,
    ];

    public function __construct(private readonly ConnectService $connect = new ConnectService())
    {
    }

    /**
     * Whether Duely may generate a link for this invoice, and why.
     *
     * ---------------------------------------------------------------------
     * RESOLUTION ORDER. Do not reorder without reading this.
     *
     *   1. A manually pasted link wins. Always, unconditionally, before
     *      anything else is consulted. It is the user's own URL — their PayPal
     *      page, their bank, their accountant's portal — and no setting Duely
     *      owns gets to override or suppress it. `never` does not touch it
     *      either: that setting governs links *Duely generates*.
     *   2. The invoice override. `none` suppresses, `generate` forces, and
     *      both beat the workspace default, because the person setting a mode
     *      on one invoice knows something about that invoice.
     *   3. The workspace default.
     *   4. The connection gates — linked account, charges enabled, invoice
     *      open, amount above zero.
     *
     * The gates come last on purpose. Asking "is this workspace connected"
     * before "does this invoice even want a link" would make a Stripe round
     * trip to answer a question already settled locally.
     * ---------------------------------------------------------------------
     *
     * @return array{generate:bool, reason:string}
     */
    public static function decide(string $workspaceMode, ?string $invoiceMode): array
    {
        $invoiceMode = $invoiceMode === null || $invoiceMode === self::INVOICE_DEFAULT
            ? null
            : $invoiceMode;

        if ($invoiceMode === self::INVOICE_NONE) {
            return ['generate' => false, 'reason' => 'invoice_none'];
        }

        if ($invoiceMode === self::INVOICE_GENERATE) {
            // Deliberately above the workspace check: `generate` exists so a
            // manual_only workspace can still say yes to one invoice. It does
            // not override `never`, though — see below.
            return $workspaceMode === self::WORKSPACE_NEVER
                ? ['generate' => false, 'reason' => 'workspace_never']
                : ['generate' => true, 'reason' => 'invoice_generate'];
        }

        return match ($workspaceMode) {
            self::WORKSPACE_NEVER => ['generate' => false, 'reason' => 'workspace_never'],
            self::WORKSPACE_MANUAL_ONLY => ['generate' => false, 'reason' => 'workspace_manual_only'],
            default => ['generate' => true, 'reason' => 'workspace_always'],
        };
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
            // A link Duely made earlier. It still has to satisfy today's
            // settings: a workspace switched to `never`, or an invoice marked
            // `none`, means the next reminder carries no button — otherwise
            // turning the feature off would only affect invoices that had not
            // been chased yet, which is not what "off" means.
            $decision = self::decide($this->workspaceMode($tenantId), $invoice['payment_link_mode'] ?? null);

            return $decision['generate'] ? $existing : null;
        }

        $result = $this->generate($tenantId, $invoice);

        return $result['ok'] ? $result['url'] : null;
    }

    /**
     * What the next reminder for this invoice will carry, without making one.
     *
     * The invoice page uses this to tell the user what their client is about to
     * receive. Guessing at that is how somebody finds out from the client.
     *
     * @return array{will_send:bool, kind:string, url:?string, reason:string}
     */
    public function plan(int $tenantId, array $invoice): array
    {
        $existing = trim((string) ($invoice['payment_url'] ?? ''));
        $isManual = $existing !== '' && !(bool) ($invoice['payment_url_is_generated'] ?? false);

        if ($isManual) {
            return ['will_send' => true, 'kind' => 'manual', 'url' => $existing, 'reason' => 'manual_link'];
        }

        $decision = self::decide($this->workspaceMode($tenantId), $invoice['payment_link_mode'] ?? null);

        if (!$decision['generate']) {
            return ['will_send' => false, 'kind' => 'none', 'url' => null, 'reason' => $decision['reason']];
        }

        if ($existing !== '') {
            return ['will_send' => true, 'kind' => 'generated', 'url' => $existing, 'reason' => $decision['reason']];
        }

        // Nothing generated yet. Whether one appears depends on the connection,
        // which is the last gate rather than the first.
        $status = $this->connect->status($tenantId);

        if (!$status['connected']) {
            return ['will_send' => false, 'kind' => 'none', 'url' => null, 'reason' => 'not_connected'];
        }

        if (!$status['charges_enabled']) {
            return ['will_send' => false, 'kind' => 'none', 'url' => null, 'reason' => 'charges_disabled'];
        }

        if (($invoice['status'] ?? '') !== Invoice::STATUS_OPEN) {
            return ['will_send' => false, 'kind' => 'none', 'url' => null, 'reason' => 'not_open'];
        }

        return ['will_send' => true, 'kind' => 'pending', 'url' => null, 'reason' => $decision['reason']];
    }

    /**
     * This workspace's default. Read straight from the column, and independent
     * of `stripe_account_id` — disconnecting Stripe must not reset it.
     */
    public function workspaceMode(int $tenantId): string
    {
        $statement = Database::connection()->prepare(
            'SELECT payment_link_mode FROM organizations WHERE id = ? LIMIT 1'
        );
        $statement->execute([$tenantId]);
        $mode = (string) ($statement->fetchColumn() ?: '');

        return in_array($mode, self::WORKSPACE_MODES, true) ? $mode : self::WORKSPACE_ALWAYS;
    }

    /**
     * Make a payment link for an invoice.
     *
     * @return array{ok:bool, url:?string, error:?string, reason:?string}
     */
    public function generate(int $tenantId, array $invoice): array
    {
        // Settled locally, before anything reaches out to Stripe. See decide()
        // for the order and why it is that order.
        $decision = self::decide($this->workspaceMode($tenantId), $invoice['payment_link_mode'] ?? null);

        if (!$decision['generate']) {
            return $this->no($decision['reason'], match ($decision['reason']) {
                'invoice_none' => 'This invoice is set to go out without a pay button.',
                'workspace_never' => 'This workspace has Duely pay buttons switched off.',
                default => 'This workspace only uses payment links you add yourself.',
            });
        }

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
