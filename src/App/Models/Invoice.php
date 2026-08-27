<?php

namespace Keel\App\Models;

use DateTimeImmutable;
use Keel\App\Services\Clock;
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
            'paid_source',
            'payment_url',
            // Per-invoice pay-button override. NULL means "follow the
            // workspace default", which is the common case.
            'payment_link_mode',
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
     * Sort keys the invoice list accepts, mapped to their ORDER BY clauses.
     *
     * The list is the only place a caller chooses ordering, and the choice
     * arrives from a query string — so it is resolved through this allowlist
     * rather than being placed into SQL.
     */
    private const SORTS = [
        'days_overdue' => 'days_overdue DESC, i.due_date ASC',
        'days_overdue_asc' => 'days_overdue ASC, i.due_date DESC',
        'due_date' => 'i.due_date ASC',
        'due_date_desc' => 'i.due_date DESC',
        'amount' => 'i.amount_cents DESC',
        'amount_asc' => 'i.amount_cents ASC',
        'number' => 'i.number ASC',
        'client' => 'c.name ASC, i.due_date ASC',
        'newest' => 'i.created_at DESC',
    ];

    public const DEFAULT_SORT = 'days_overdue';

    /**
     * The invoice list: each invoice with its client, its days overdue, and the
     * status of any chase running against it.
     *
     * @param array{status?:string, client_id?:int|null, search?:string, sort?:string} $filters
     */
    public static function listWithContext(
        int $tenantId,
        array $filters = [],
        int $limit = 100,
        int $offset = 0
    ): array {
        $status = (string) ($filters['status'] ?? 'all');
        $clientId = $filters['client_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = (string) ($filters['sort'] ?? self::DEFAULT_SORT);

        // The application clock is the only clock. MySQL's CURDATE() can sit in
        // a different timezone to PHP, which would make the list disagree with
        // the scheduler about how overdue an invoice is — and send the wrong
        // rung of the sequence a day early.
        $today = self::today($filters['as_of'] ?? null);

        $bindings = [$tenantId];
        $where = 'i.tenant_id = ?';

        // "overdue" is a view over open invoices, not a stored status.
        if ($status === 'overdue') {
            $where .= ' AND i.status = ? AND i.due_date < ?';
            $bindings[] = self::STATUS_OPEN;
            $bindings[] = $today;
        } elseif (in_array($status, [self::STATUS_OPEN, self::STATUS_PAID, self::STATUS_VOID], true)) {
            $where .= ' AND i.status = ?';
            $bindings[] = $status;
        }

        if ($clientId !== null && (int) $clientId > 0) {
            $where .= ' AND i.client_id = ?';
            $bindings[] = (int) $clientId;
        }

        if ($search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $where .= ' AND (i.number LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ?)';
            array_push($bindings, $needle, $needle, $needle, $needle);
        }

        $orderBy = self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT];

        $sql = 'SELECT i.*,
                       c.name AS client_name,
                       c.email AS client_email,
                       c.company AS client_company,
                       DATEDIFF(?, i.due_date) AS days_overdue,
                       ch.id AS chase_id,
                       ch.status AS chase_status,
                       ch.paused_reason AS chase_paused_reason,
                       ch.next_send_at AS chase_next_send_at,
                       ch.current_position AS chase_position
                FROM invoices i
                INNER JOIN clients c
                        ON c.id = i.client_id
                       AND c.tenant_id = i.tenant_id
                LEFT JOIN chases ch
                       ON ch.invoice_id = i.id
                      AND ch.tenant_id = i.tenant_id
                WHERE ' . $where . '
                ORDER BY ' . $orderBy . ', i.id DESC
                LIMIT ? OFFSET ?';

        // The DATEDIFF placeholder sits in the SELECT list, ahead of every
        // WHERE binding, so it goes to the front of the positional list.
        array_unshift($bindings, $today);

        return static::runPaged($sql, $bindings, $limit, $offset)->fetchAll();
    }

    /**
     * Row count for the same filters, so the list can paginate.
     *
     * @param array{status?:string, client_id?:int|null, search?:string} $filters
     */
    public static function countWithFilters(int $tenantId, array $filters = []): int
    {
        $status = (string) ($filters['status'] ?? 'all');
        $clientId = $filters['client_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        $today = self::today($filters['as_of'] ?? null);

        $bindings = [$tenantId];
        $where = 'i.tenant_id = ?';

        if ($status === 'overdue') {
            $where .= ' AND i.status = ? AND i.due_date < ?';
            $bindings[] = self::STATUS_OPEN;
            $bindings[] = $today;
        } elseif (in_array($status, [self::STATUS_OPEN, self::STATUS_PAID, self::STATUS_VOID], true)) {
            $where .= ' AND i.status = ?';
            $bindings[] = $status;
        }

        if ($clientId !== null && (int) $clientId > 0) {
            $where .= ' AND i.client_id = ?';
            $bindings[] = (int) $clientId;
        }

        if ($search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $where .= ' AND (i.number LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ?)';
            array_push($bindings, $needle, $needle, $needle, $needle);
        }

        $sql = 'SELECT COUNT(*)
                FROM invoices i
                INNER JOIN clients c
                        ON c.id = i.client_id
                       AND c.tenant_id = i.tenant_id
                WHERE ' . $where;

        return (int) static::run($sql, $bindings)->fetchColumn();
    }

    /**
     * Counts for the status filter tabs.
     *
     * @return array{all:int, open:int, overdue:int, paid:int, void:int}
     */
    public static function statusTallies(int $tenantId, ?DateTimeImmutable $asOf = null): array
    {
        $sql = 'SELECT
                    COUNT(*) AS all_count,
                    SUM(status = ?) AS open_count,
                    SUM(status = ? AND due_date < ?) AS overdue_count,
                    SUM(status = ?) AS paid_count,
                    SUM(status = ?) AS void_count
                FROM invoices
                WHERE tenant_id = ?';

        $row = static::run($sql, [
            self::STATUS_OPEN,
            self::STATUS_OPEN,
            self::today($asOf),
            self::STATUS_PAID,
            self::STATUS_VOID,
            $tenantId,
        ])->fetch() ?: [];

        return [
            'all' => (int) ($row['all_count'] ?? 0),
            'open' => (int) ($row['open_count'] ?? 0),
            'overdue' => (int) ($row['overdue_count'] ?? 0),
            'paid' => (int) ($row['paid_count'] ?? 0),
            'void' => (int) ($row['void_count'] ?? 0),
        ];
    }

    /**
     * @return string[] the sort keys the list accepts
     */
    public static function sortKeys(): array
    {
        return array_keys(self::SORTS);
    }

    /**
     * The application's idea of today, as a Y-m-d string for binding.
     *
     * Every overdue calculation — the list, the tallies, and daysOverdue() —
     * routes through this, so PHP is the single clock. Passing dates in rather
     * than calling CURDATE() also keeps the database from silently disagreeing
     * when it runs in a different timezone to the app, which is the normal case
     * on shared hosting.
     */
    public static function today(?DateTimeImmutable $asOf = null): string
    {
        return ($asOf ?? new DateTimeImmutable('today'))->format('Y-m-d');
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
            'paid_at' => Clock::toDatabase($paidAt ?? Clock::now()),
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
