<?php

namespace Keel\App\Services;

use Keel\Core\Database;
use PDOException;
use Throwable;

/**
 * Idempotency for the Connect webhook.
 *
 * Its own table, not `stripe_events`. The two endpoints have different signing
 * secrets on purpose, and a shared log would undo that: an event id claimed on
 * one endpoint would silently suppress the same id arriving on the other, which
 * is exactly the cross-endpoint replay the separate secrets exist to prevent.
 *
 * The unique index on `stripe_event_id` is what makes this correct — not the
 * SELECT. Two concurrent deliveries both look, both see nothing, and only one
 * insert survives.
 */
class ConnectEventLog
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    /**
     * Take ownership of an event, or report it as already seen.
     *
     * @return array{claimed:bool, id:?int, previous_status:?string}
     */
    public function claim(string $eventId, string $type, ?string $accountId, ?string $payload = null): array
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO connect_events
                    (stripe_event_id, stripe_account_id, type, status, payload, received_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $eventId,
                $accountId,
                mb_substr($type, 0, 100),
                self::STATUS_PROCESSING,
                $payload === null ? null : mb_substr($payload, 0, 60000),
                Clock::toDatabase(Clock::now()),
            ]);

            return [
                'claimed' => true,
                'id' => (int) Database::connection()->lastInsertId(),
                'previous_status' => null,
            ];
        } catch (PDOException $exception) {
            if (!str_contains($exception->getMessage(), '1062')) {
                throw $exception;
            }

            $existing = $this->find($eventId);

            if ($existing === null) {
                return ['claimed' => false, 'id' => null, 'previous_status' => null];
            }

            // A failed attempt is work Stripe is right to retry. Re-claiming it
            // is the point of the retry; treating it as a duplicate would
            // strand the event, which is worse than the original failure.
            if ($existing['status'] === self::STATUS_FAILED) {
                $reclaim = Database::connection()->prepare(
                    'UPDATE connect_events SET status = ?, error = NULL, processed_at = NULL
                     WHERE id = ? AND status = ?'
                );
                $reclaim->execute([self::STATUS_PROCESSING, (int) $existing['id'], self::STATUS_FAILED]);

                // rowCount settles a race between two retries: only the
                // delivery that actually flipped the row proceeds.
                if ($reclaim->rowCount() > 0) {
                    return [
                        'claimed' => true,
                        'id' => (int) $existing['id'],
                        'previous_status' => self::STATUS_FAILED,
                    ];
                }
            }

            return [
                'claimed' => false,
                'id' => (int) $existing['id'],
                'previous_status' => (string) $existing['status'],
            ];
        }
    }

    public function markProcessed(int $id, ?int $tenantId = null): void
    {
        $this->finish($id, self::STATUS_PROCESSED, $tenantId, null);
    }

    public function markIgnored(int $id, string $reason, ?int $tenantId = null): void
    {
        $this->finish($id, self::STATUS_IGNORED, $tenantId, $reason);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->finish($id, self::STATUS_FAILED, null, $error);
    }

    public function find(string $eventId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM connect_events WHERE stripe_event_id = ? LIMIT 1'
        );
        $statement->execute([$eventId]);

        return $statement->fetch() ?: null;
    }

    private function finish(int $id, string $status, ?int $tenantId, ?string $error): void
    {
        try {
            $statement = Database::connection()->prepare(
                'UPDATE connect_events
                 SET status = ?, tenant_id = COALESCE(?, tenant_id), error = ?, processed_at = ?
                 WHERE id = ?'
            );
            $statement->execute([
                $status,
                $tenantId,
                $error === null ? null : mb_substr($error, 0, 500),
                Clock::toDatabase(Clock::now()),
                $id,
            ]);
        } catch (Throwable $exception) {
            // Losing the audit line must not fail the webhook, or Stripe
            // retries an event that was in fact handled.
            error_log('[Duely] Could not update Connect event log: ' . $exception->getMessage());
        }
    }
}
