<?php

namespace Keel\App\Jobs;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\App\Services\ChaseSender;
use Keel\App\Services\Clock;
use Throwable;

/**
 * One tick of the cadence engine.
 *
 * Fans out across the tenants that have work due and processes each one. The
 * fan-out is deliberately id-only: `tenantsWithWorkDue()` returns tenant ids
 * and nothing else, and every subsequent call is scoped to one of them, so the
 * worker never holds rows from two tenants at once.
 *
 * A tenant whose processing throws does not stop the others — one broken
 * mailbox should not stall everyone else's reminders.
 */
class ProcessDueChasesJob implements Job
{
    /** Chases processed per tenant per tick, so no one tenant hogs a worker. */
    private const PER_TENANT_LIMIT = 25;

    public function __construct(private readonly ?ChaseSender $sender = null)
    {
    }

    public function handle(array $data): void
    {
        $this->run(
            isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            isset($data['now']) ? new DateTimeImmutable((string) $data['now']) : null
        );
    }

    /**
     * @param callable|null $sleeper injected by the worker so sends are spaced
     * @return array{tenants:int, sent:int, skipped:int, failed:int}
     */
    public function run(?int $onlyTenantId = null, ?DateTimeImmutable $now = null, ?callable $sleeper = null): array
    {
        $now ??= Clock::now();
        $sender = $this->sender ?? new ChaseSender();

        $tenantIds = $onlyTenantId !== null
            ? [$onlyTenantId]
            : Chase::tenantsWithWorkDue($now);

        $totals = ['tenants' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($tenantIds as $tenantId) {
            $totals['tenants']++;

            try {
                $result = $sender->processDueForTenant($tenantId, $now, self::PER_TENANT_LIMIT, $sleeper);

                $totals['sent'] += $result['sent'];
                $totals['skipped'] += $result['skipped'];
                $totals['failed'] += $result['failed'];
            } catch (Throwable $exception) {
                // One tenant's failure must not stop the queue for everyone.
                error_log('[Duely] Chase processing failed for tenant ' . $tenantId . ': ' . $exception->getMessage());
                $totals['failed']++;
            }
        }

        return $totals;
    }
}
