<?php

namespace Keel\App\Jobs;

use DateTimeImmutable;
use Keel\App\Models\EmailAccount;
use Keel\App\Services\Clock;
use Keel\App\Services\ImapPoller;
use Keel\Core\Database;
use Throwable;

/**
 * One inbox-polling tick.
 *
 * Runs every five minutes. That interval is a deliberate trade: often enough
 * that a reply is noticed long before the next rung of a sequence is due, rare
 * enough that Duely is not hammering someone's mail provider all day.
 *
 * Like the cadence job, the fan-out is id-only — tenant ids come back from a
 * query that returns nothing else, and every call after that is scoped to one
 * tenant.
 */
class PollInboxesJob implements Job
{
    /** How often a mailbox should be revisited. */
    public const INTERVAL_SECONDS = 300;

    public function __construct(private readonly ?ImapPoller $poller = null)
    {
    }

    public function handle(array $data): void
    {
        $this->run(
            isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            isset($data['now']) ? new DateTimeImmutable((string) $data['now'], Clock::utc()) : null
        );
    }

    /**
     * @return array{tenants:int, accounts:int, examined:int, recorded:int, paused:int, stopped:int, errors:string[]}
     */
    public function run(?int $onlyTenantId = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $poller = $this->poller ?? new ImapPoller();

        $tenantIds = $onlyTenantId !== null ? [$onlyTenantId] : $this->tenantsWithPollableMailboxes($now);

        $totals = [
            'tenants' => 0, 'accounts' => 0, 'examined' => 0,
            'recorded' => 0, 'paused' => 0, 'stopped' => 0, 'errors' => [],
        ];

        foreach ($tenantIds as $tenantId) {
            $totals['tenants']++;

            try {
                $result = $poller->pollTenant($tenantId, $now);

                foreach (['accounts', 'examined', 'recorded', 'paused', 'stopped'] as $key) {
                    $totals[$key] += $result[$key];
                }

                $totals['errors'] = array_merge($totals['errors'], $result['errors']);
            } catch (Throwable $exception) {
                // A reply missed for one tenant is bad; missing every tenant's
                // replies because one mailbox misbehaved would be far worse.
                $totals['errors'][] = 'Tenant ' . $tenantId . ': ' . $exception->getMessage();
                error_log('[Duely] Inbox polling failed for tenant ' . $tenantId . ': ' . $exception->getMessage());
            }
        }

        return $totals;
    }

    /**
     * Tenants with a mailbox that is due to be polled.
     *
     * Ids only — no tenant-owned row leaves this method.
     *
     * @return int[]
     */
    private function tenantsWithPollableMailboxes(DateTimeImmutable $now): array
    {
        $due = Clock::toDatabase($now->modify('-' . self::INTERVAL_SECONDS . ' seconds'));

        $sql = 'SELECT DISTINCT tenant_id FROM email_accounts
                WHERE status = ?
                  AND imap_host IS NOT NULL
                  AND imap_username IS NOT NULL
                  AND (imap_last_polled_at IS NULL OR imap_last_polled_at <= ?)
                ORDER BY tenant_id ASC';

        $statement = Database::connection()->prepare($sql);
        $statement->execute([EmailAccount::STATUS_ACTIVE, $due]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
