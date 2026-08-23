<?php

namespace Keel\App\Models;

use DateTimeImmutable;
use Keel\Core\Env;

/**
 * One outbound reminder in a chase.
 *
 * `rfc_message_id` and `in_reply_to` are what make the follow-ups land as a
 * single thread in the client's mail app: rung 1 sets the root Message-ID, and
 * every later rung carries In-Reply-To plus a References chain back to it.
 */
class ChaseMessage extends BaseModel
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BOUNCED = 'bounced';

    protected static function table(): string
    {
        return 'chase_messages';
    }

    protected static function columns(): array
    {
        return [
            'chase_id',
            'sequence_step_id',
            'position',
            'to_email',
            'from_email',
            'subject',
            'body_text',
            'body_html',
            'rfc_message_id',
            'in_reply_to',
            'references_header',
            'status',
            'scheduled_for',
            'sent_at',
            'failed_reason',
        ];
    }

    public static function forChase(int $tenantId, int $chaseId): array
    {
        $sql = 'SELECT * FROM chase_messages
                WHERE tenant_id = ? AND chase_id = ?
                ORDER BY position ASC, id ASC';

        return static::run($sql, [$tenantId, $chaseId])->fetchAll();
    }

    public static function lastSent(int $tenantId, int $chaseId): ?array
    {
        $sql = 'SELECT * FROM chase_messages
                WHERE tenant_id = ? AND chase_id = ? AND status = ?
                ORDER BY position DESC, id DESC
                LIMIT 1';

        return static::run($sql, [$tenantId, $chaseId, self::STATUS_SENT])->fetch() ?: null;
    }

    public static function findByRfcMessageId(int $tenantId, string $rfcMessageId): ?array
    {
        return static::findOneBy($tenantId, 'rfc_message_id', $rfcMessageId);
    }

    /**
     * Resolve an inbound In-Reply-To / References token back to the chase it
     * belongs to, so a reply can pause the right ladder.
     */
    public static function chaseIdForMessageId(int $tenantId, string $rfcMessageId): ?int
    {
        $sql = 'SELECT chase_id FROM chase_messages
                WHERE tenant_id = ? AND rfc_message_id = ?
                LIMIT 1';

        $chaseId = static::run($sql, [$tenantId, $rfcMessageId])->fetchColumn();

        return $chaseId === false ? null : (int) $chaseId;
    }

    public static function markSent(int $tenantId, int $id, ?DateTimeImmutable $sentAt = null): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_SENT,
            'sent_at' => ($sentAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'failed_reason' => null,
        ]);
    }

    public static function markFailed(int $tenantId, int $id, string $reason): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_FAILED,
            'failed_reason' => $reason,
        ]);
    }

    public static function markBounced(int $tenantId, int $id, string $reason): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_BOUNCED,
            'failed_reason' => $reason,
        ]);
    }

    public static function sentCount(int $tenantId, int $chaseId): int
    {
        $sql = 'SELECT COUNT(*) FROM chase_messages
                WHERE tenant_id = ? AND chase_id = ? AND status = ?';

        return (int) static::run($sql, [$tenantId, $chaseId, self::STATUS_SENT])->fetchColumn();
    }

    // -------------------------------------------------------- RFC822 helpers

    /**
     * Mint a globally unique Message-ID, domain-anchored to APP_URL so replies
     * route back to a host we own.
     */
    public static function newMessageId(): string
    {
        $host = parse_url((string) Env::get('APP_URL', 'https://duely.app'), PHP_URL_HOST) ?: 'duely.app';

        return '<' . bin2hex(random_bytes(16)) . '@' . $host . '>';
    }

    /**
     * Build the References header for a follow-up: the root message plus every
     * message already sent on this chase, oldest first, as RFC 5322 requires.
     */
    public static function referencesFor(int $tenantId, int $chaseId, ?string $rootMessageId): ?string
    {
        $sql = 'SELECT rfc_message_id FROM chase_messages
                WHERE tenant_id = ? AND chase_id = ? AND status = ?
                ORDER BY position ASC, id ASC';

        $ids = static::run($sql, [$tenantId, $chaseId, self::STATUS_SENT])->fetchAll(\PDO::FETCH_COLUMN);

        if ($rootMessageId !== null) {
            array_unshift($ids, $rootMessageId);
        }

        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));

        return $ids === [] ? null : implode(' ', $ids);
    }
}
