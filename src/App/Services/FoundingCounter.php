<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Services\PlanService;
use Keel\Core\Database;
use Throwable;

/**
 * How many founding places are actually left.
 *
 * The number comes from `founding_slots` and from nowhere else. It is never
 * rounded down to look scarcer, never floored at some minimum to look
 * available, and there is no countdown timer anywhere near it. If it cannot be
 * read, the marketing pages show nothing rather than a guess — a made-up
 * scarcity number is the single fastest way to make an honest product feel like
 * a sales funnel, and the offer is real enough not to need help.
 *
 * "Left" means `tenant_id IS NULL`: genuinely free right now. A slot whose hold
 * has expired but which ReleaseExpiredFoundingSlotsJob has not yet returned is
 * deliberately *not* counted, because somebody clicking through on that number
 * could not actually claim it. Counting it would make the page slightly more
 * optimistic and slightly untrue, which is the trade this class exists to
 * refuse.
 */
class FoundingCounter
{
    /**
     * Long enough to spare the database on a busy landing page, short enough
     * that somebody watching the number see it move. The count does not need to
     * be live to the second; it needs to be true.
     */
    public const CACHE_SECONDS = 60;

    /** Under this, the copy changes tone — not urgency. */
    public const FEW_LEFT = 10;

    public const STATE_AVAILABLE = 'available';
    public const STATE_FEW = 'few';
    public const STATE_GONE = 'gone';

    /**
     * Within one request the answer cannot change, and a page renders the
     * counter in more than one place.
     */
    private static ?array $memo = null;

    /**
     * @return array{
     *     remaining:int, taken:int, total:int, state:string,
     *     price_cents:int, price:string, cached:bool
     * }|null  null when the count cannot be read
     */
    public function snapshot(?DateTimeImmutable $now = null): ?array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cached = $this->readCache($now);

        if ($cached !== null) {
            return self::$memo = $cached + ['cached' => true];
        }

        try {
            $remaining = $this->countFromDatabase();
        } catch (Throwable $exception) {
            // A landing page must render for a stranger on a box where the
            // database is unreachable. No number is a fine answer; a wrong one
            // is not.
            error_log('[Duely] Founding counter unavailable: ' . $exception->getMessage());

            return null;
        }

        $snapshot = $this->describe($remaining);

        $this->writeCache($snapshot, $now);

        return self::$memo = $snapshot + ['cached' => false];
    }

    /**
     * Forget the cached value.
     *
     * Called when a slot is claimed or released, so the counter moves as soon
     * as the thing it counts does rather than up to a minute later.
     */
    public static function forget(): void
    {
        self::$memo = null;

        $path = self::cachePath();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------- internals

    /**
     * The authoritative count. Free rows, nothing else.
     */
    private function countFromDatabase(): int
    {
        return (int) Database::connection()
            ->query('SELECT COUNT(*) FROM founding_slots WHERE tenant_id IS NULL')
            ->fetchColumn();
    }

    /**
     * @return array{remaining:int, taken:int, total:int, state:string, price_cents:int, price:string}
     */
    private function describe(int $remaining): array
    {
        $total = PlanService::FOUNDING_SLOTS;
        $remaining = max(0, min($remaining, $total));

        return [
            'remaining' => $remaining,
            'taken' => $total - $remaining,
            'total' => $total,
            'state' => match (true) {
                $remaining === 0 => self::STATE_GONE,
                $remaining < self::FEW_LEFT => self::STATE_FEW,
                default => self::STATE_AVAILABLE,
            },
            'price_cents' => PlanService::FOUNDING_PRICE_CENTS,
            'price' => MoneyParser::format(PlanService::FOUNDING_PRICE_CENTS, 'USD'),
        ];
    }

    private function readCache(?DateTimeImmutable $now): ?array
    {
        $path = self::cachePath();

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload) || !isset($payload['at'], $payload['snapshot'])) {
            return null;
        }

        $age = ($now ?? Clock::now())->getTimestamp() - (int) $payload['at'];

        // A negative age means the clock moved backwards, which is not a reason
        // to trust the file for the next minute.
        if ($age < 0 || $age >= self::CACHE_SECONDS) {
            return null;
        }

        return is_array($payload['snapshot']) ? $payload['snapshot'] : null;
    }

    private function writeCache(array $snapshot, ?DateTimeImmutable $now): void
    {
        $path = self::cachePath();
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode([
            'at' => ($now ?? Clock::now())->getTimestamp(),
            'snapshot' => $snapshot,
        ]);

        if ($payload === false) {
            return;
        }

        // Written to a neighbour and renamed. A landing page reading the file
        // while another request is halfway through writing it would otherwise
        // get truncated JSON and fall back to a query -- harmless, but the
        // rename costs nothing and makes it impossible.
        $temporary = $path . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private static function cachePath(): string
    {
        return dirname(__DIR__, 3) . '/storage/cache/founding-slots.json';
    }
}
