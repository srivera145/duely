<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Database;

/**
 * Keeps Duely inside what a consumer mailbox will tolerate.
 *
 * Gmail, Outlook and most shared hosts throttle — or lock — an account that
 * sends a burst over SMTP. Because Duely sends from the user's own mailbox,
 * tripping that limit does not degrade Duely, it breaks the user's email. So
 * the limits here are deliberately well below any provider's published cap,
 * and the jitter exists so a queue of forty invoices does not produce forty
 * messages with identical spacing, which is itself a spam signal.
 */
class SendRateLimiter
{
    public const MAX_PER_HOUR = 30;
    public const MAX_PER_DAY = 200;

    public const MIN_GAP_SECONDS = 20;
    public const MAX_GAP_SECONDS = 90;

    /**
     * May this account send right now?
     *
     * @return array{allowed:bool, reason:?string, retry_after:?DateTimeImmutable, hour_count:int, day_count:int}
     */
    public function check(int $tenantId, int $emailAccountId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $hourCount = $this->countSince($tenantId, $emailAccountId, $now->modify('-1 hour'));
        $dayCount = $this->countSince($tenantId, $emailAccountId, $now->modify('-1 day'));

        if ($hourCount >= self::MAX_PER_HOUR) {
            return [
                'allowed' => false,
                'reason' => 'This mailbox has already sent ' . $hourCount . ' reminders in the last hour.',
                // Retry once the oldest send in the window ages out.
                'retry_after' => $this->windowFreesAt($tenantId, $emailAccountId, $now->modify('-1 hour'), '+1 hour', $now),
                'hour_count' => $hourCount,
                'day_count' => $dayCount,
            ];
        }

        if ($dayCount >= self::MAX_PER_DAY) {
            return [
                'allowed' => false,
                'reason' => 'This mailbox has already sent ' . $dayCount . ' reminders today.',
                'retry_after' => $this->windowFreesAt($tenantId, $emailAccountId, $now->modify('-1 day'), '+1 day', $now),
                'hour_count' => $hourCount,
                'day_count' => $dayCount,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'retry_after' => null,
            'hour_count' => $hourCount,
            'day_count' => $dayCount,
        ];
    }

    /**
     * How long to wait before the next send, in seconds.
     *
     * Random rather than fixed so a run of sends does not arrive on a metronome.
     */
    public function jitterSeconds(): int
    {
        return random_int(self::MIN_GAP_SECONDS, self::MAX_GAP_SECONDS);
    }

    /**
     * Messages actually accepted by this mailbox since a given moment.
     */
    public function countSince(int $tenantId, int $emailAccountId, DateTimeImmutable $since): int
    {
        $sql = 'SELECT COUNT(*) FROM chase_messages
                WHERE tenant_id = ?
                  AND email_account_id = ?
                  AND status = ?
                  AND sent_at IS NOT NULL
                  AND sent_at >= ?';

        $statement = Database::connection()->prepare($sql);
        $statement->execute([
            $tenantId,
            $emailAccountId,
            \Keel\App\Models\ChaseMessage::STATUS_SENT,
            Clock::toDatabase($since),
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * When the oldest send inside the window falls out of it, freeing a slot.
     */
    private function windowFreesAt(
        int $tenantId,
        int $emailAccountId,
        DateTimeImmutable $since,
        string $windowLength,
        DateTimeImmutable $now
    ): DateTimeImmutable {
        $sql = 'SELECT MIN(sent_at) FROM chase_messages
                WHERE tenant_id = ?
                  AND email_account_id = ?
                  AND status = ?
                  AND sent_at IS NOT NULL
                  AND sent_at >= ?';

        $statement = Database::connection()->prepare($sql);
        $statement->execute([
            $tenantId,
            $emailAccountId,
            \Keel\App\Models\ChaseMessage::STATUS_SENT,
            Clock::toDatabase($since),
        ]);

        $oldest = $statement->fetchColumn();

        if (!is_string($oldest) || $oldest === '') {
            return $now->modify('+15 minutes');
        }

        // Stored UTC-naive; parsing it in the server's local zone would put
        // the retry time hours off in either direction.
        return Clock::fromDatabase($oldest)?->modify($windowLength) ?? $now->modify('+15 minutes');
    }
}
