<?php

namespace Keel\App\Models;

use DateTimeImmutable;
use Keel\Core\Database;

/**
 * Something that came back from the client: a reply, a bounce, an auto-responder.
 *
 * Ingestion is idempotent. IMAP polling re-reads overlapping windows, so the
 * unique index on (tenant_id, rfc_message_id) plus record()'s INSERT IGNORE
 * means the same message can be seen twice without pausing a chase twice.
 */
class ReplyEvent extends BaseModel
{
    public const TYPE_REPLY = 'reply';
    public const TYPE_BOUNCE = 'bounce';
    public const TYPE_AUTO_REPLY = 'auto_reply';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_UNKNOWN = 'unknown';

    protected static function table(): string
    {
        return 'reply_events';
    }

    protected static function columns(): array
    {
        return [
            'chase_id',
            'chase_message_id',
            'email_account_id',
            'type',
            'from_email',
            'subject',
            'snippet',
            'rfc_message_id',
            'in_reply_to',
            'thread_id',
            'raw_headers',
            'received_at',
            'processed_at',
            'action_taken',
        ];
    }

    /**
     * Insert if unseen. Returns the row id, or null when the message was already
     * recorded — the caller uses null as "nothing new to act on".
     */
    public static function record(int $tenantId, array $attributes): ?int
    {
        $attributes['received_at'] = $attributes['received_at']
            ?? (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $sql = 'INSERT IGNORE INTO reply_events
                    (tenant_id, chase_id, chase_message_id, email_account_id, type,
                     from_email, subject, snippet, rfc_message_id, in_reply_to,
                     thread_id, raw_headers, received_at)
                VALUES
                    (:tenant_id, :chase_id, :chase_message_id, :email_account_id, :type,
                     :from_email, :subject, :snippet, :rfc_message_id, :in_reply_to,
                     :thread_id, :raw_headers, :received_at)';

        $statement = Database::connection()->prepare($sql);
        $statement->execute([
            'tenant_id' => $tenantId,
            'chase_id' => $attributes['chase_id'] ?? null,
            'chase_message_id' => $attributes['chase_message_id'] ?? null,
            'email_account_id' => $attributes['email_account_id'] ?? null,
            'type' => $attributes['type'] ?? self::TYPE_UNKNOWN,
            'from_email' => $attributes['from_email'] ?? null,
            'subject' => $attributes['subject'] ?? null,
            'snippet' => $attributes['snippet'] ?? null,
            'rfc_message_id' => $attributes['rfc_message_id'],
            'in_reply_to' => $attributes['in_reply_to'] ?? null,
            'thread_id' => $attributes['thread_id'] ?? null,
            'raw_headers' => $attributes['raw_headers'] ?? null,
            'received_at' => $attributes['received_at'],
        ]);

        if ($statement->rowCount() === 0) {
            return null;
        }

        return (int) Database::connection()->lastInsertId();
    }

    public static function findByRfcMessageId(int $tenantId, string $rfcMessageId): ?array
    {
        return static::findOneBy($tenantId, 'rfc_message_id', $rfcMessageId);
    }

    public static function forChase(int $tenantId, int $chaseId): array
    {
        $sql = 'SELECT * FROM reply_events
                WHERE tenant_id = ? AND chase_id = ?
                ORDER BY received_at ASC, id ASC';

        return static::run($sql, [$tenantId, $chaseId])->fetchAll();
    }

    /**
     * Events the reply handler has not acted on yet.
     */
    public static function unprocessed(int $tenantId, int $limit = 100): array
    {
        $sql = 'SELECT * FROM reply_events
                WHERE tenant_id = ? AND processed_at IS NULL
                ORDER BY received_at ASC, id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId], $limit, 0)->fetchAll();
    }

    /**
     * Tenant ids with unprocessed events. Ids only — no tenant-owned row leaves
     * this method — so the global worker can fan out to per-tenant calls.
     *
     * @return int[]
     */
    public static function tenantsWithUnprocessed(): array
    {
        $sql = 'SELECT DISTINCT tenant_id FROM reply_events
                WHERE processed_at IS NULL
                ORDER BY tenant_id ASC';

        return array_map('intval', static::run($sql)->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function markProcessed(int $tenantId, int $id, string $actionTaken): bool
    {
        return static::update($tenantId, $id, [
            'processed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'action_taken' => $actionTaken,
        ]);
    }

    /**
     * Has a human replied on this chase? Auto-responders and bounces do not count,
     * so an out-of-office does not silently kill a chase.
     */
    public static function hasHumanReply(int $tenantId, int $chaseId): bool
    {
        $sql = 'SELECT 1 FROM reply_events
                WHERE tenant_id = ? AND chase_id = ? AND type = ?
                LIMIT 1';

        return (bool) static::run($sql, [$tenantId, $chaseId, self::TYPE_REPLY])->fetchColumn();
    }

    /**
     * Recent inbound activity joined to the invoice it concerns, for the inbox view.
     */
    public static function recentWithContext(int $tenantId, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT r.*,
                       i.number AS invoice_number,
                       i.amount_cents,
                       i.currency,
                       c.name AS client_name
                FROM reply_events r
                LEFT JOIN chases ch ON ch.id = r.chase_id AND ch.tenant_id = r.tenant_id
                LEFT JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = r.tenant_id
                LEFT JOIN clients c ON c.id = i.client_id AND c.tenant_id = r.tenant_id
                WHERE r.tenant_id = ?
                ORDER BY r.received_at DESC, r.id DESC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId], $limit, $offset)->fetchAll();
    }
}
