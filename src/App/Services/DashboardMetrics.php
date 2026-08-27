<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\Invoice;
use Keel\Core\Database;

/**
 * The numbers on the dashboard.
 *
 * Each card is one aggregate query. The temptation on a screen like this is to
 * load the invoices and total them in PHP, which is fine at ten invoices and
 * ruinous at five hundred — so every figure here is computed by the database,
 * and the active-chases table is a single join rather than a lookup per row.
 *
 * "Today" is supplied by the application rather than taken from the database
 * clock, for the same reason it is everywhere else in Duely: MySQL and PHP can
 * sit in different timezones, and an invoice must not be overdue in one place
 * and not the other.
 */
class DashboardMetrics
{
    /** The window "recovered recently" reports on. */
    private const RECOVERY_WINDOW_DAYS = 30;

    /** How far back the average-days-to-payment figure looks. */
    private const PAYMENT_HISTORY_DAYS = 180;

    /**
     * Every card in one call.
     *
     * @return array{
     *     outstanding:array<string,int>, outstanding_total:int, outstanding_currency:string,
     *     overdue_count:int, overdue_cents:int,
     *     average_days_to_payment:?float, paid_sample:int,
     *     recovered_cents:int, recovered_count:int, recovered_window_days:int
     * }
     */
    public function cards(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        return array_merge(
            $this->outstanding($tenantId, $now),
            $this->overdue($tenantId, $now),
            $this->averageDaysToPayment($tenantId, $now),
            $this->recovered($tenantId, $now)
        );
    }

    /**
     * Money still owed, split by currency because summing across them is
     * meaningless.
     *
     * @return array{outstanding:array<string,int>, outstanding_total:int, outstanding_currency:string}
     */
    public function outstanding(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $sql = 'SELECT currency, SUM(amount_cents) AS total_cents, COUNT(*) AS invoice_count
                FROM invoices
                WHERE tenant_id = ? AND status = ?
                GROUP BY currency
                ORDER BY total_cents DESC';

        $rows = $this->select($sql, [$tenantId, Invoice::STATUS_OPEN]);

        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[(string) $row['currency']] = (int) $row['total_cents'];
        }

        // The headline figure is the largest currency; the rest are shown beside it.
        $primaryCurrency = $rows === [] ? 'USD' : (string) $rows[0]['currency'];

        return [
            'outstanding' => $byCurrency,
            'outstanding_total' => $rows === [] ? 0 : (int) $rows[0]['total_cents'],
            'outstanding_currency' => $primaryCurrency,
        ];
    }

    /**
     * @return array{overdue_count:int, overdue_cents:int}
     */
    public function overdue(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $today = Invoice::today($now);

        $sql = 'SELECT COUNT(*) AS overdue_count, COALESCE(SUM(amount_cents), 0) AS overdue_cents
                FROM invoices
                WHERE tenant_id = ? AND status = ? AND due_date < ?';

        $row = $this->selectOne($sql, [$tenantId, Invoice::STATUS_OPEN, $today]);

        return [
            'overdue_count' => (int) ($row['overdue_count'] ?? 0),
            'overdue_cents' => (int) ($row['overdue_cents'] ?? 0),
        ];
    }

    /**
     * How long invoices actually take to get paid, measured from the due date.
     *
     * Negative means paid early. Null means there is not enough history yet,
     * which the card says rather than showing a confident zero.
     *
     * @return array{average_days_to_payment:?float, paid_sample:int}
     */
    public function averageDaysToPayment(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $since = Clock::toDatabase($now->modify('-' . self::PAYMENT_HISTORY_DAYS . ' days'));

        $sql = 'SELECT AVG(DATEDIFF(paid_at, due_date)) AS average_days, COUNT(*) AS paid_count
                FROM invoices
                WHERE tenant_id = ?
                  AND status = ?
                  AND paid_at IS NOT NULL
                  AND paid_at >= ?';

        $row = $this->selectOne($sql, [$tenantId, Invoice::STATUS_PAID, $since]);

        $count = (int) ($row['paid_count'] ?? 0);

        return [
            'average_days_to_payment' => $count === 0 || $row['average_days'] === null
                ? null
                : round((float) $row['average_days'], 1),
            'paid_sample' => $count,
        ];
    }

    /**
     * Money that came in recently — the number that tells someone Duely is
     * doing its job.
     *
     * @return array{recovered_cents:int, recovered_count:int, recovered_window_days:int}
     */
    public function recovered(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $since = Clock::toDatabase($now->modify('-' . self::RECOVERY_WINDOW_DAYS . ' days'));

        $sql = 'SELECT COALESCE(SUM(amount_cents), 0) AS recovered_cents, COUNT(*) AS recovered_count
                FROM invoices
                WHERE tenant_id = ?
                  AND status = ?
                  AND paid_at IS NOT NULL
                  AND paid_at >= ?';

        $row = $this->selectOne($sql, [$tenantId, Invoice::STATUS_PAID, $since]);

        return [
            'recovered_cents' => (int) ($row['recovered_cents'] ?? 0),
            'recovered_count' => (int) ($row['recovered_count'] ?? 0),
            'recovered_window_days' => self::RECOVERY_WINDOW_DAYS,
        ];
    }

    /**
     * The active-chases table: one row per live chase, with everything the
     * screen shows, in a single join.
     *
     * The step count and sent count come from correlated aggregates rather
     * than from a query per row — at five hundred invoices the difference is
     * the difference between a usable screen and an unusable one.
     */
    public function activeChases(int $tenantId, ?DateTimeImmutable $now = null, int $limit = 100): array
    {
        $today = Invoice::today($now);

        $sql = 'SELECT
                    ch.id AS chase_id,
                    ch.status AS chase_status,
                    ch.paused_reason,
                    ch.current_position,
                    ch.next_send_at,
                    i.id AS invoice_id,
                    i.number,
                    i.amount_cents,
                    i.currency,
                    i.due_date,
                    DATEDIFF(?, i.due_date) AS days_overdue,
                    c.name AS client_name,
                    c.email AS client_email,
                    c.company AS client_company,
                    c.timezone AS client_timezone,
                    s.name AS sequence_name,
                    (SELECT COUNT(*) FROM sequence_steps st
                      WHERE st.sequence_id = ch.sequence_id AND st.tenant_id = ch.tenant_id) AS total_steps,
                    (SELECT COUNT(*) FROM chase_messages m
                      WHERE m.chase_id = ch.id AND m.tenant_id = ch.tenant_id AND m.status = ?) AS sent_count,
                    (SELECT MAX(m2.sent_at) FROM chase_messages m2
                      WHERE m2.chase_id = ch.id AND m2.tenant_id = ch.tenant_id AND m2.status = ?) AS last_sent_at,
                    (SELECT r.snippet FROM reply_events r
                      WHERE r.chase_id = ch.id AND r.tenant_id = ch.tenant_id AND r.type = ?
                      ORDER BY r.received_at DESC LIMIT 1) AS last_reply_snippet
                FROM chases ch
                INNER JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = ch.tenant_id
                INNER JOIN clients c ON c.id = i.client_id AND c.tenant_id = ch.tenant_id
                INNER JOIN sequences s ON s.id = ch.sequence_id AND s.tenant_id = ch.tenant_id
                WHERE ch.tenant_id = ?
                  AND ch.status IN (?, ?, ?)
                ORDER BY
                    ch.status = ? DESC,
                    days_overdue DESC,
                    ch.next_send_at IS NULL,
                    ch.next_send_at ASC
                LIMIT ? OFFSET ?';

        $bindings = [
            $today,
            ChaseMessage::STATUS_SENT,
            ChaseMessage::STATUS_SENT,
            \Keel\App\Models\ReplyEvent::TYPE_REPLY,
            $tenantId,
            Chase::STATUS_SCHEDULED,
            Chase::STATUS_ACTIVE,
            Chase::STATUS_PAUSED,
            // Anything needing a decision floats to the top.
            Chase::STATUS_PAUSED,
        ];

        $statement = Database::connection()->prepare($sql);

        $position = 1;
        foreach ($bindings as $value) {
            $statement->bindValue($position++, $value);
        }
        $statement->bindValue($position++, max(1, min($limit, 500)), \PDO::PARAM_INT);
        $statement->bindValue($position, 0, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Anything the user should look at: replies and bounces not yet acted on.
     *
     * `chase_id IS NOT NULL` is defence in depth. The poller no longer stores an
     * unmatched message at all, but rows written before that fix exist, and this
     * panel is where they surfaced: unrelated mail rendered as "someone
     * replied", snippet and all, including Duely's own login codes. A row with
     * no chase is not about an invoice, so it is not this panel's business.
     */
    public function needsAttention(int $tenantId, int $limit = 10): array
    {
        $sql = 'SELECT r.*,
                       i.number AS invoice_number,
                       i.id AS invoice_id,
                       c.name AS client_name
                FROM reply_events r
                LEFT JOIN chases ch ON ch.id = r.chase_id AND ch.tenant_id = r.tenant_id
                LEFT JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = r.tenant_id
                LEFT JOIN clients c ON c.id = i.client_id AND c.tenant_id = r.tenant_id
                WHERE r.tenant_id = ?
                  AND r.chase_id IS NOT NULL
                  AND r.type IN (?, ?)
                ORDER BY r.received_at DESC, r.id DESC
                LIMIT ? OFFSET ?';

        $statement = Database::connection()->prepare($sql);
        $statement->bindValue(1, $tenantId);
        $statement->bindValue(2, \Keel\App\Models\ReplyEvent::TYPE_REPLY);
        $statement->bindValue(3, \Keel\App\Models\ReplyEvent::TYPE_BOUNCE);
        $statement->bindValue(4, max(1, min($limit, 50)), \PDO::PARAM_INT);
        $statement->bindValue(5, 0, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    // ------------------------------------------------------------ internals

    private function select(string $sql, array $bindings): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    private function selectOne(string $sql, array $bindings): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetch() ?: [];
    }
}
