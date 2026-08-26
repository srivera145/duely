<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Auth;
use Keel\Core\Database;
use Throwable;

/**
 * The daily AI budget, and the ledger behind it.
 *
 * This started life inside ToneAssistService. It moved here when a second
 * feature — reading an invoice document — needed the same cap, because two
 * private copies of a rate limiter is two daily budgets: a workspace would get
 * twenty rewrites *and* twenty extractions, which is not what "twenty calls a
 * tenant a day" was ever meant to mean.
 *
 * One ledger, one budget, whatever spends it.
 */
class AiUsage
{
    /** Calls per workspace per rolling day, across every AI feature. */
    public const DAILY_LIMIT = 20;

    /**
     * What is left today.
     *
     * @return array{allowed:bool, used:int, limit:int, resets_at:?string}
     */
    public function allowance(int $tenantId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $since = Clock::toDatabase($now->modify('-1 day'));

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM ai_usage WHERE tenant_id = ? AND created_at >= ?'
        );
        $statement->execute([$tenantId, $since]);
        $used = (int) $statement->fetchColumn();

        return [
            'allowed' => $used < self::DAILY_LIMIT,
            'used' => $used,
            'limit' => self::DAILY_LIMIT,
            'resets_at' => $used < self::DAILY_LIMIT ? null : $this->oldestCallExpiry($tenantId, $now),
        ];
    }

    /**
     * Write one call to the ledger.
     *
     * Never throws. Accounting must not be the thing that breaks the feature it
     * is accounting for.
     */
    public function record(
        int $tenantId,
        string $action,
        string $model,
        array $usage,
        string $outcome,
        ?string $reason,
        float $startedAt,
        ?DateTimeImmutable $now = null
    ): void {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO ai_usage
                    (tenant_id, user_id, action, model, input_tokens, output_tokens,
                     cache_read_tokens, cache_write_tokens, outcome, failure_reason,
                     duration_ms, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $tenantId,
                Auth::id(),
                mb_substr($action, 0, 32),
                mb_substr($model, 0, 64),
                (int) ($usage['input_tokens'] ?? 0),
                (int) ($usage['output_tokens'] ?? 0),
                (int) ($usage['cache_read_tokens'] ?? 0),
                (int) ($usage['cache_write_tokens'] ?? 0),
                $outcome,
                $reason === null ? null : mb_substr($reason, 0, 255),
                (int) round((microtime(true) - $startedAt) * 1000),
                Clock::toDatabase($now ?? Clock::now()),
            ]);
        } catch (Throwable $exception) {
            error_log('[Duely] Could not record AI usage: ' . $exception->getMessage());
        }
    }

    /**
     * When the oldest call in the window ages out, freeing a slot.
     */
    private function oldestCallExpiry(int $tenantId, DateTimeImmutable $now): ?string
    {
        $statement = Database::connection()->prepare(
            'SELECT MIN(created_at) FROM ai_usage WHERE tenant_id = ? AND created_at >= ?'
        );
        $statement->execute([$tenantId, Clock::toDatabase($now->modify('-1 day'))]);

        $oldest = $statement->fetchColumn();

        if (!is_string($oldest) || $oldest === '') {
            return null;
        }

        return Clock::toDatabase(Clock::fromDatabase($oldest)?->modify('+1 day'));
    }
}
