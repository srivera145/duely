<?php

namespace Keel\App\Models;

use DateTimeImmutable;
use Keel\Core\Database;

/**
 * A running follow-up campaign against one invoice.
 *
 * The schema enforces one chase per invoice via a unique index on invoice_id,
 * so a double-click or a re-import can never produce two ladders sending to the
 * same client about the same money.
 */
class Chase extends BaseModel
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STOPPED = 'stopped';

    public const PAUSE_CLIENT_REPLIED = 'client_replied';
    public const PAUSE_INVOICE_PAID = 'invoice_paid';
    public const PAUSE_BOUNCED = 'bounced';
    public const PAUSE_MANUAL = 'manual';
    public const PAUSE_NEEDS_REAUTH = 'needs_reauth';

    protected static function table(): string
    {
        return 'chases';
    }

    protected static function columns(): array
    {
        return [
            'invoice_id',
            'sequence_id',
            'email_account_id',
            'status',
            'current_position',
            'next_send_at',
            'paused_reason',
            'paused_at',
            'thread_id',
            'root_message_id',
            'started_at',
            'completed_at',
            'stopped_at',
        ];
    }

    public static function forInvoice(int $tenantId, int $invoiceId): ?array
    {
        return static::findOneBy($tenantId, 'invoice_id', $invoiceId);
    }

    public static function byStatus(int $tenantId, string $status, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM chases
                WHERE tenant_id = ? AND status = ?
                ORDER BY next_send_at IS NULL, next_send_at ASC, id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId, $status], $limit, $offset)->fetchAll();
    }

    /**
     * Chases whose next rung is due, for this tenant only.
     */
    public static function due(int $tenantId, ?DateTimeImmutable $asOf = null, int $limit = 100): array
    {
        $now = ($asOf ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        $sql = 'SELECT * FROM chases
                WHERE tenant_id = ?
                  AND status IN (?, ?)
                  AND next_send_at IS NOT NULL
                  AND next_send_at <= ?
                ORDER BY next_send_at ASC, id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged(
            $sql,
            [$tenantId, self::STATUS_SCHEDULED, self::STATUS_ACTIVE, $now],
            $limit,
            0
        )->fetchAll();
    }

    /**
     * Tenant ids that currently have work due.
     *
     * The scheduler is global but tenant data is not: this returns ids only —
     * no tenant-owned row ever leaves this method — so the worker can fan out
     * and then call due() with a concrete tenant id for the actual rows.
     *
     * @return int[]
     */
    public static function tenantsWithWorkDue(?DateTimeImmutable $asOf = null): array
    {
        $now = ($asOf ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        $sql = 'SELECT DISTINCT tenant_id FROM chases
                WHERE status IN (?, ?)
                  AND next_send_at IS NOT NULL
                  AND next_send_at <= ?
                ORDER BY tenant_id ASC';

        $rows = static::run($sql, [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, $now])
            ->fetchAll(\PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    }

    /**
     * Chase joined to the invoice and client it is chasing, for the dashboard.
     */
    public static function withContext(int $tenantId, int $id): ?array
    {
        $sql = 'SELECT ch.*,
                       i.number AS invoice_number,
                       i.amount_cents,
                       i.currency,
                       i.due_date,
                       i.status AS invoice_status,
                       c.name AS client_name,
                       c.email AS client_email,
                       s.name AS sequence_name
                FROM chases ch
                INNER JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = ch.tenant_id
                INNER JOIN clients c ON c.id = i.client_id AND c.tenant_id = ch.tenant_id
                INNER JOIN sequences s ON s.id = ch.sequence_id AND s.tenant_id = ch.tenant_id
                WHERE ch.tenant_id = ? AND ch.id = ?
                LIMIT 1';

        return static::run($sql, [$tenantId, $id])->fetch() ?: null;
    }

    /**
     * Board view: every live chase with its invoice and client.
     */
    public static function liveBoard(int $tenantId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT ch.*,
                       i.number AS invoice_number,
                       i.amount_cents,
                       i.currency,
                       i.due_date,
                       c.name AS client_name,
                       c.email AS client_email
                FROM chases ch
                INNER JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = ch.tenant_id
                INNER JOIN clients c ON c.id = i.client_id AND c.tenant_id = ch.tenant_id
                WHERE ch.tenant_id = ? AND ch.status IN (?, ?, ?)
                ORDER BY ch.next_send_at IS NULL, ch.next_send_at ASC, ch.id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged(
            $sql,
            [$tenantId, self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_PAUSED],
            $limit,
            $offset
        )->fetchAll();
    }

    /**
     * @return array<string, int> status => count
     */
    public static function statusCounts(int $tenantId): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM chases
                WHERE tenant_id = ?
                GROUP BY status';

        $counts = [];
        foreach (static::run($sql, [$tenantId])->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    // ---------------------------------------------------------- state changes

    public static function advance(int $tenantId, int $id, int $position, ?DateTimeImmutable $nextSendAt): bool
    {
        return static::update($tenantId, $id, [
            'status' => $nextSendAt === null ? self::STATUS_COMPLETED : self::STATUS_ACTIVE,
            'current_position' => $position,
            'next_send_at' => $nextSendAt?->format('Y-m-d H:i:s'),
            'completed_at' => $nextSendAt === null
                ? (new DateTimeImmutable())->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public static function pause(int $tenantId, int $id, string $reason): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_PAUSED,
            'paused_reason' => $reason,
            'paused_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'next_send_at' => null,
        ]);
    }

    public static function resume(int $tenantId, int $id, ?DateTimeImmutable $nextSendAt): bool
    {
        return static::update($tenantId, $id, [
            'status' => $nextSendAt === null ? self::STATUS_COMPLETED : self::STATUS_ACTIVE,
            'paused_reason' => null,
            'paused_at' => null,
            'next_send_at' => $nextSendAt?->format('Y-m-d H:i:s'),
        ]);
    }

    public static function stop(int $tenantId, int $id): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_STOPPED,
            'next_send_at' => null,
            'stopped_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Record the RFC822 anchors from the first message so every later rung can
     * thread onto it instead of starting a new conversation.
     */
    public static function anchorThread(int $tenantId, int $id, string $rootMessageId, ?string $threadId = null): bool
    {
        return static::update($tenantId, $id, [
            'root_message_id' => $rootMessageId,
            'thread_id' => $threadId ?? $rootMessageId,
        ]);
    }

    /**
     * Pause every live chase pointed at an invoice's client — used when a reply
     * or a payment should quiet the whole relationship, not just one ladder.
     */
    public static function pauseAllForClient(int $tenantId, int $clientId, string $reason): int
    {
        $sql = 'UPDATE chases ch
                INNER JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = ch.tenant_id
                SET ch.status = ?,
                    ch.paused_reason = ?,
                    ch.paused_at = ?,
                    ch.next_send_at = NULL
                WHERE ch.tenant_id = ?
                  AND i.client_id = ?
                  AND ch.status IN (?, ?)';

        return static::run($sql, [
            self::STATUS_PAUSED,
            $reason,
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            $tenantId,
            $clientId,
            self::STATUS_SCHEDULED,
            self::STATUS_ACTIVE,
        ])->rowCount();
    }

    /**
     * Start a chase for an invoice, entering the ladder at the rung the invoice
     * has already reached. Returns the existing chase id if one is present, so
     * the unique index on invoice_id is never the thing that reports the clash.
     */
    public static function start(
        int $tenantId,
        int $invoiceId,
        int $sequenceId,
        ?int $emailAccountId,
        ?DateTimeImmutable $nextSendAt,
        int $startPosition = 0
    ): int {
        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            $existing = static::forInvoice($tenantId, $invoiceId);

            if ($existing !== null) {
                $connection->commit();

                return (int) $existing['id'];
            }

            $chaseId = static::create($tenantId, [
                'invoice_id' => $invoiceId,
                'sequence_id' => $sequenceId,
                'email_account_id' => $emailAccountId,
                'status' => self::STATUS_SCHEDULED,
                'current_position' => $startPosition,
                'next_send_at' => $nextSendAt?->format('Y-m-d H:i:s'),
                'started_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $connection->commit();

            return $chaseId;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}
