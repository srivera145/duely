<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Invoice;
use Keel\Core\Activity;
use Keel\Core\Database;
use Keel\Core\Env;
use Keel\Core\Mailer;

/**
 * What Duely does when Stripe says a client paid.
 *
 * ---------------------------------------------------------------------------
 * Partial payments are deliberately not "supported" yet, and this is the
 * holding position rather than an oversight.
 *
 * The schema has one `amount_cents` and a binary open/paid status, so a part
 * payment has nowhere to live. Both automatic answers are wrong:
 *
 *   Mark it paid, and the user loses the rest of their money quietly.
 *   Say nothing, and the next reminder tells a client who just sent $1,600
 *   that they owe $3,200 — which is the single most damaging thing this
 *   product can do to somebody's client relationship.
 *
 * So neither happens automatically. The payment is recorded, the invoice is
 * left open, the chase is left alone, and the user is told — by email and in
 * the timeline — so a human decides. Proper support means an amount-paid
 * column, a partially-paid status, and reminder copy that can say "the
 * remaining $1,600", which is a schema change and a copy change, not a patch.
 * ---------------------------------------------------------------------------
 */
class PaymentReceiver
{
    public function __construct(
        private readonly InvoicePaymentMarker $marker = new InvoicePaymentMarker(),
    ) {
    }

    /**
     * Apply one confirmed payment.
     *
     * @param array{invoice_id:int, tenant_id:int, amount_cents:int, currency:string, event_id:string, object_id:?string} $payment
     * @param int $accountTenantId the workspace that owns the Stripe account the event arrived on
     * @return array{applied:bool, outcome:string, reason:?string, invoice_id:?int}
     */
    public function apply(array $payment, int $accountTenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $invoiceId = (int) $payment['invoice_id'];
        $claimedTenant = (int) $payment['tenant_id'];

        if ($invoiceId <= 0 || $claimedTenant <= 0) {
            return $this->skip('missing_metadata', 'The payment carried no invoice reference.');
        }

        // The account the event arrived on decides whose workspace this is. The
        // metadata only says which invoice. Trusting the metadata's tenant
        // alone would let anyone who can craft a payment link mark another
        // workspace's invoice paid.
        if ($accountTenantId !== $claimedTenant) {
            return $this->skip(
                'tenant_mismatch',
                'The payment claimed a workspace that does not own the Stripe account it arrived on.'
            );
        }

        $tenantId = $claimedTenant;
        $invoice = Invoice::find($tenantId, $invoiceId);

        // Invoice::find is tenant-scoped, so this also catches an invoice id
        // belonging to somebody else entirely.
        if ($invoice === null) {
            return $this->skip('unknown_invoice', 'No such invoice in that workspace.');
        }

        if (($invoice['status'] ?? '') === Invoice::STATUS_PAID) {
            return ['applied' => false, 'outcome' => 'already_paid', 'reason' => null, 'invoice_id' => $invoiceId];
        }

        $received = (int) $payment['amount_cents'];
        $due = (int) $invoice['amount_cents'];

        $outcome = match (true) {
            $received < $due => 'partial',
            $received > $due => 'overpaid',
            default => 'settled',
        };

        $this->recordPayment($tenantId, $invoiceId, $payment, $outcome, $now);

        if ($outcome === 'partial') {
            return $this->handlePartial($tenantId, $invoice, $received, $due, $now);
        }

        // Full or over: the invoice is settled. An overpayment is the client's
        // to reclaim from the user, and stopping the reminders is still right.
        $this->marker->markPaid($tenantId, $invoice, InvoicePaymentMarker::SOURCE_WEBHOOK, $now);

        if ($outcome === 'overpaid') {
            Activity::log('invoice.overpaid', 'Invoice', $invoiceId, [
                'received_cents' => $received,
                'due_cents' => $due,
            ]);
        }

        return ['applied' => true, 'outcome' => $outcome, 'reason' => null, 'invoice_id' => $invoiceId];
    }

    // -------------------------------------------------------------- internals

    /**
     * Short of the total: record it, leave everything alone, tell the human.
     */
    private function handlePartial(
        int $tenantId,
        array $invoice,
        int $received,
        int $due,
        DateTimeImmutable $now
    ): array {
        $invoiceId = (int) $invoice['id'];
        $currency = (string) $invoice['currency'];

        Activity::log('invoice.part_paid', 'Invoice', $invoiceId, [
            'received_cents' => $received,
            'due_cents' => $due,
            'outstanding_cents' => $due - $received,
        ]);

        $this->notifyPartial($tenantId, $invoice, $received, $due, $currency);

        return [
            'applied' => true,
            'outcome' => 'partial',
            'reason' => 'Part payment recorded. The invoice is still open and the reminders are unchanged.',
            'invoice_id' => $invoiceId,
        ];
    }

    /**
     * Tell the user, because nobody else can decide what to do about it.
     */
    private function notifyPartial(int $tenantId, array $invoice, int $received, int $due, string $currency): void
    {
        $owner = $this->owner($tenantId);

        if ($owner === null) {
            return;
        }

        $number = (string) $invoice['number'];
        $paid = MoneyParser::format($received, $currency);
        $total = MoneyParser::format($due, $currency);
        $left = MoneyParser::format($due - $received, $currency);
        $url = rtrim((string) Env::get('APP_URL', ''), '/') . '/invoices/' . (int) $invoice['id'];

        Mailer::send(
            (string) $owner['email'],
            (string) ($owner['name'] ?? $owner['email']),
            'Part payment on ' . $number . ' — your reminders are still running',
            '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;'
            . 'max-width:480px;margin:0 auto;padding:32px;color:#171717;">'
            . '<h2 style="margin:0 0 16px;font-size:20px;color:#0a0a0a;">'
            . htmlspecialchars($number, ENT_QUOTES, 'UTF-8') . ' was part paid</h2>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#525252;">'
            . 'Your client paid <strong>' . htmlspecialchars($paid, ENT_QUOTES, 'UTF-8') . '</strong> '
            . 'of <strong>' . htmlspecialchars($total, ENT_QUOTES, 'UTF-8') . '</strong>, leaving '
            . htmlspecialchars($left, ENT_QUOTES, 'UTF-8') . ' outstanding.</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#525252;">'
            . 'Duely has <strong>not</strong> marked the invoice paid and has <strong>not</strong> '
            . 'stopped the reminders, because it cannot tell whether the rest is coming. The next '
            . 'reminder will ask for the full amount. If that is not what you want, pause the chase '
            . 'or mark the invoice paid yourself.</p>'
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;'
            . 'padding:12px 24px;border-radius:8px;font-weight:600;font-size:15px;">Open the invoice</a>'
            . '</div>'
        );
    }

    private function recordPayment(
        int $tenantId,
        int $invoiceId,
        array $payment,
        string $outcome,
        DateTimeImmutable $now
    ): void {
        $statement = Database::connection()->prepare(
            'INSERT INTO invoice_payments
                (tenant_id, invoice_id, stripe_event_id, stripe_object_id, amount_cents, currency, outcome, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE outcome = VALUES(outcome)'
        );

        $statement->execute([
            $tenantId,
            $invoiceId,
            (string) $payment['event_id'],
            $payment['object_id'] ?? null,
            (int) $payment['amount_cents'],
            strtoupper((string) $payment['currency']),
            $outcome,
            Clock::toDatabase($now),
        ]);
    }

    private function owner(int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, name, email FROM users WHERE organization_id = ? ORDER BY (role = ?) DESC, id ASC LIMIT 1'
        );
        $statement->execute([$tenantId, 'owner']);

        return $statement->fetch() ?: null;
    }

    private function skip(string $outcome, string $reason): array
    {
        return ['applied' => false, 'outcome' => $outcome, 'reason' => $reason, 'invoice_id' => null];
    }
}
