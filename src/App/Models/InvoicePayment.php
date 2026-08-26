<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * A payment Stripe reported against an invoice.
 *
 * Rows are written by the Connect webhook and never edited afterwards. They
 * exist so a part payment has somewhere to live: the invoice itself has one
 * amount and a binary status, so without this table a payment short of the
 * total would either vanish or be rounded up into "paid".
 */
class InvoicePayment
{
    public const OUTCOME_SETTLED = 'settled';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_OVERPAID = 'overpaid';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forInvoice(int $tenantId, int $invoiceId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM invoice_payments
             WHERE tenant_id = ? AND invoice_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute([$tenantId, $invoiceId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * Total received so far, in cents. Used to tell the user how much of a
     * part-paid invoice is still outstanding.
     */
    public static function receivedCents(int $tenantId, int $invoiceId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM invoice_payments
             WHERE tenant_id = ? AND invoice_id = ?'
        );
        $statement->execute([$tenantId, $invoiceId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Invoices that took money but are still open — the ones needing a human.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function needingAttention(int $tenantId, int $limit = 20): array
    {
        $statement = Database::connection()->prepare(
            'SELECT p.*, i.number, i.amount_cents AS invoice_amount_cents, i.status AS invoice_status
             FROM invoice_payments p
             INNER JOIN invoices i ON i.id = p.invoice_id AND i.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.outcome = ? AND i.status = ?
             ORDER BY p.created_at DESC
             LIMIT ' . max(1, $limit)
        );
        $statement->execute([$tenantId, self::OUTCOME_PARTIAL, Invoice::STATUS_OPEN]);

        return $statement->fetchAll() ?: [];
    }
}
