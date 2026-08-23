<?php

namespace Keel\App\Models;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An invoice Duely chases. Amounts are integer cents throughout; no float ever
 * touches a monetary value on the way in or out of this model.
 */
class Invoice extends BaseModel
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    protected static function table(): string
    {
        return 'invoices';
    }

    protected static function columns(): array
    {
        return [
            'client_id',
            'number',
            'amount_cents',
            'currency',
            'issue_date',
            'due_date',
            'status',
            'paid_at',
            'payment_url',
            'external_ref',
            'notes',
        ];
    }

    public static function findByNumber(int $tenantId, string $number): ?array
    {
        return static::findOneBy($tenantId, 'number', trim($number));
    }

    public static function forClient(int $tenantId, int $clientId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM invoices
                WHERE tenant_id = ? AND client_id = ?
                ORDER BY due_date DESC, id DESC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId, $clientId], $limit, $offset)->fetchAll();
    }

    public static function open(int $tenantId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM invoices
                WHERE tenant_id = ? AND status = ?
                ORDER BY due_date ASC, id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId, self::STATUS_OPEN], $limit, $offset)->fetchAll();
    }

    /**
     * Open invoices past their due date, oldest first.
     */
    public static function overdue(int $tenantId, ?DateTimeImmutable $asOf = null, int $limit = 100, int $offset = 0): array
    {
        $today = ($asOf ?? new DateTimeImmutable('today'))->format('Y-m-d');

        $sql = 'SELECT * FROM invoices
                WHERE tenant_id = ? AND status = ? AND due_date < ?
                ORDER BY due_date ASC, id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId, self::STATUS_OPEN, $today], $limit, $offset)->fetchAll();
    }

    /**
     * Open invoices with no chase attached yet: the queue for "start chasing".
     */
    public static function unchased(int $tenantId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT i.* FROM invoices i
                LEFT JOIN chases ch
                       ON ch.invoice_id = i.id
                      AND ch.tenant_id = i.tenant_id
                WHERE i.tenant_id = ? AND i.status = ? AND ch.id IS NULL
                ORDER BY i.due_date ASC, i.id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId, self::STATUS_OPEN], $limit, $offset)->fetchAll();
    }

    /**
     * Invoice joined to its client, for detail views and message rendering.
     */
    public static function withClient(int $tenantId, int $id): ?array
    {
        $sql = 'SELECT i.*,
                       c.name AS client_name,
                       c.email AS client_email,
                       c.company AS client_company,
                       c.timezone AS client_timezone
                FROM invoices i
                INNER JOIN clients c
                        ON c.id = i.client_id
                       AND c.tenant_id = i.tenant_id
                WHERE i.tenant_id = ? AND i.id = ?
                LIMIT 1';

        return static::run($sql, [$tenantId, $id])->fetch() ?: null;
    }

    public static function markPaid(int $tenantId, int $id, ?DateTimeImmutable $paidAt = null): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_PAID,
            'paid_at' => ($paidAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public static function markVoid(int $tenantId, int $id): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_VOID,
            'paid_at' => null,
        ]);
    }

    /**
     * Outstanding total in cents, keyed by currency. Summing across currencies
     * would be meaningless, so callers get the split.
     *
     * @return array<string, int>
     */
    public static function outstandingByCurrency(int $tenantId): array
    {
        $sql = 'SELECT currency, SUM(amount_cents) AS total_cents
                FROM invoices
                WHERE tenant_id = ? AND status = ?
                GROUP BY currency';

        $totals = [];
        foreach (static::run($sql, [$tenantId, self::STATUS_OPEN])->fetchAll() as $row) {
            $totals[(string) $row['currency']] = (int) $row['total_cents'];
        }

        return $totals;
    }

    /**
     * Whole days an invoice is past due as of $asOf. Negative before the due date.
     * This is the number sequence_steps.offset_days is compared against.
     */
    public static function daysOverdue(array $invoice, ?DateTimeImmutable $asOf = null): int
    {
        $due = new DateTimeImmutable((string) $invoice['due_date'] . ' 00:00:00');
        $now = ($asOf ?? new DateTimeImmutable('today'))->setTime(0, 0, 0);

        return (int) $due->diff($now)->format('%r%a');
    }

    /**
     * Parse a decimal money string ("1234.50", "1,234.5") into integer cents
     * without ever building a float. Used by CSV import.
     */
    public static function centsFromDecimal(string $amount): int
    {
        $amount = str_replace([',', ' ', "\xC2\xA0"], '', trim($amount));

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Not a valid money amount: ' . $amount);
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = (int) str_pad($matches[3] ?? '0', 2, '0', STR_PAD_RIGHT);

        return $sign * ($whole * 100 + $fraction);
    }
}
