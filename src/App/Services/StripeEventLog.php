<?php

namespace Keel\App\Services;

use Keel\Core\Database;
use Throwable;

/**
 * Records every Stripe event exactly once.
 *
 * Stripe retries any delivery that does not return 2xx, and will occasionally
 * deliver the same event twice even when it did. Without a record of what has
 * already been handled, a retried `checkout.session.completed` grants a second
 * subscription and a retried founding-slot claim burns a second place.
 *
 * The unique index on the event id is what actually guarantees this: claim()
 * inserts, and a duplicate insert means another delivery got there first.
 */
class StripeEventLog
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    /**
     * Take ownership of an event, or report that it is already handled.
     *
     * @return array{claimed:bool, id:?int, previous_status:?string}
     */
    public function claim(string $eventId, string $type, ?string $payload = null): array
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO stripe_events (stripe_event_id, type, payload, status)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->execute([
                $eventId,
                mb_substr($type, 0, 100),
                // Truncated rather than dropped: enough to debug a bad delivery
                // without keeping unbounded payloads forever.
                $payload === null ? null : mb_substr($payload, 0, 60000),
                self::STATUS_RECEIVED,
            ]);

            return [
                'claimed' => true,
                'id' => (int) Database::connection()->lastInsertId(),
                'previous_status' => null,
            ];
        } catch (\PDOException $exception) {
            if (!str_contains($exception->getMessage(), '1062')) {
                throw $exception;
            }

            // Seen before. What happens next depends on how it went last time.
            $existing = $this->find($eventId);

            if ($existing === null) {
                return ['claimed' => false, 'id' => null, 'previous_status' => null];
            }

            // A previous attempt failed, so this delivery is Stripe retrying
            // work that never completed. Re-claiming it is the point of the
            // retry — treating it as a duplicate would strand the event
            // permanently, which is worse than the failure itself.
            if ($existing['status'] === self::STATUS_FAILED) {
                $reclaim = Database::connection()->prepare(
                    'UPDATE stripe_events
                     SET status = ?, error = NULL, processed_at = NULL
                     WHERE id = ? AND status = ?'
                );
                $reclaim->execute([self::STATUS_RECEIVED, (int) $existing['id'], self::STATUS_FAILED]);

                // rowCount guards a race between two concurrent retries: only
                // the one that actually flipped the row proceeds.
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

    public function markIgnored(int $id, string $reason): void
    {
        $this->finish($id, self::STATUS_IGNORED, null, $reason);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->finish($id, self::STATUS_FAILED, null, $error);
    }

    public function find(string $eventId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM stripe_events WHERE stripe_event_id = ? LIMIT 1'
        );
        $statement->execute([$eventId]);

        return $statement->fetch() ?: null;
    }

    /**
     * Recent events, for a support or admin view.
     */
    public function recent(int $limit = 50): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, stripe_event_id, type, tenant_id, status, error, received_at, processed_at
             FROM stripe_events
             ORDER BY id DESC
             LIMIT ?'
        );
        $statement->bindValue(1, max(1, min($limit, 200)), \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function finish(int $id, string $status, ?int $tenantId, ?string $error): void
    {
        try {
            $statement = Database::connection()->prepare(
                'UPDATE stripe_events
                 SET status = ?, tenant_id = COALESCE(?, tenant_id), error = ?, processed_at = ?
                 WHERE id = ?'
            );
            $statement->execute([
                $status,
                $tenantId,
                $error === null ? null : mb_substr($error, 0, 2000),
                Clock::toDatabase(Clock::now()),
                $id,
            ]);
        } catch (Throwable $exception) {
            // Losing the audit line must not fail the webhook, or Stripe will
            // retry an event that was in fact handled.
            error_log('[Duely] Could not update Stripe event log: ' . $exception->getMessage());
        }
    }
}
