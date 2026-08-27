<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\Core\Database;

/**
 * Is anything broken right now?
 *
 * The page this feeds is the first one opened in the morning, so it answers one
 * question and answers it above the fold: is the worker alive, and is anything
 * piling up.
 *
 * **Counts and error strings only.** No message bodies, no client names, no
 * subject lines. An operations page is looked at constantly and half-read; it
 * must not be a place where customer content accumulates in front of somebody
 * who has no reason to be reading it. A failure is "17 failures, SMTP timeout"
 * — which is everything needed to act, and nothing more.
 */
class OperationsMonitor
{
    /** Past this, the worker is considered not to have run. */
    private const WORKER_STALE_SECONDS = 300;

    /** An IMAP account that has not polled in this long is not polling. */
    private const IMAP_STALE_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $sections = [
            'worker' => $this->worker($now),
            'queue' => $this->queue($now),
            'chases' => $this->stuckChases($now),
            'sends' => $this->failedSends($now),
            'mailboxes' => $this->mailboxes($now),
            'webhooks' => $this->webhooks(),
            'ai' => $this->ai($now),
        ];

        // The page shows red first, so the page has to know what red is.
        $alerts = [];

        foreach ($sections as $key => $section) {
            foreach ($section['alerts'] ?? [] as $alert) {
                $alerts[] = array_merge($alert, ['section' => $key]);
            }
        }

        return ['sections' => $sections, 'alerts' => $alerts, 'checked_at' => Clock::toDatabase($now)];
    }

    // ------------------------------------------------------------- the worker

    /**
     * Liveness, inferred from work actually completing.
     *
     * There is no heartbeat table, and adding one would mean a green light that
     * says the heartbeat is running rather than that the work is. The last
     * reserved job and the last sent message are evidence of the thing itself.
     */
    private function worker(?DateTimeImmutable $now): array
    {
        $lastReserved = $this->scalar('SELECT MAX(reserved_at) FROM jobs');
        $lastSent = $this->scalar('SELECT MAX(sent_at) FROM chase_messages');
        $pending = (int) $this->scalar('SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL');

        $latest = max((string) $lastReserved, (string) $lastSent);
        $secondsSince = $latest === '' ? null : max(0, $now->getTimestamp() - strtotime($latest . ' UTC'));

        $alerts = [];

        // Idle is not dead: a queue with nothing in it and a worker that has not
        // run for an hour is a quiet night, not an outage. Only a backlog that
        // is not moving means something.
        if ($pending > 0 && ($secondsSince === null || $secondsSince > self::WORKER_STALE_SECONDS)) {
            $alerts[] = [
                'level' => 'critical',
                'label' => 'Worker not running',
                'detail' => $pending . ' job' . ($pending === 1 ? '' : 's') . ' waiting, nothing picked up'
                    . ($secondsSince === null ? ' ever' : ' for ' . $this->duration($secondsSince)),
            ];
        }

        return [
            'last_activity_at' => $latest ?: null,
            'seconds_since' => $secondsSince,
            'pending_jobs' => $pending,
            'alerts' => $alerts,
        ];
    }

    private function queue(?DateTimeImmutable $now): array
    {
        $depth = (int) $this->scalar('SELECT COUNT(*) FROM jobs');
        $failed = (int) $this->scalar(
            'SELECT COUNT(*) FROM failed_jobs WHERE failed_at >= ?',
            [Clock::toDatabase($now->modify('-24 hours'))]
        );

        $byClass = $this->rows(
            'SELECT job_class, COUNT(*) AS total, MIN(available_at) AS oldest
             FROM jobs GROUP BY job_class ORDER BY total DESC LIMIT 20'
        );

        $recentFailures = $this->rows(
            'SELECT job_class, COUNT(*) AS total, MAX(failed_at) AS latest
             FROM failed_jobs WHERE failed_at >= ?
             GROUP BY job_class ORDER BY total DESC LIMIT 20',
            [Clock::toDatabase($now->modify('-24 hours'))]
        );

        $alerts = [];

        if ($failed > 0) {
            $alerts[] = [
                'level' => 'warning',
                'label' => $failed . ' failed job' . ($failed === 1 ? '' : 's') . ' in 24h',
                'detail' => implode(', ', array_map(
                    static fn (array $r): string => $r['job_class'] . ' x' . $r['total'],
                    $recentFailures
                )),
            ];
        }

        return [
            'depth' => $depth,
            'failed_24h' => $failed,
            'by_class' => $byClass,
            'failures' => $recentFailures,
            'alerts' => $alerts,
        ];
    }

    // -------------------------------------------------------------- the work

    /**
     * Chases that should have sent and did not.
     *
     * The one signal that means customers are being let down silently: their
     * reminders are simply not going out and nothing has errored to say so.
     */
    private function stuckChases(?DateTimeImmutable $now): array
    {
        $cutoff = Clock::toDatabase($now->modify('-1 hour'));

        $stuck = (int) $this->scalar(
            'SELECT COUNT(*) FROM chases
             WHERE status = ? AND next_send_at IS NOT NULL AND next_send_at < ?',
            [Chase::STATUS_SCHEDULED, $cutoff]
        );

        $oldest = $this->scalar(
            'SELECT MIN(next_send_at) FROM chases
             WHERE status = ? AND next_send_at IS NOT NULL AND next_send_at < ?',
            [Chase::STATUS_SCHEDULED, $cutoff]
        );

        $byTenant = $this->rows(
            'SELECT c.tenant_id, o.name AS tenant_name, COUNT(*) AS total
             FROM chases c
             LEFT JOIN organizations o ON o.id = c.tenant_id
             WHERE c.status = ? AND c.next_send_at IS NOT NULL AND c.next_send_at < ?
             GROUP BY c.tenant_id, o.name
             ORDER BY total DESC LIMIT 20',
            [Chase::STATUS_SCHEDULED, $cutoff]
        );

        $alerts = [];

        if ($stuck > 0) {
            $alerts[] = [
                'level' => 'critical',
                'label' => $stuck . ' chase' . ($stuck === 1 ? '' : 's') . ' overdue to send',
                'detail' => 'Oldest due ' . (string) $oldest . '. Reminders are not going out.',
            ];
        }

        return ['stuck' => $stuck, 'oldest_due' => $oldest, 'by_tenant' => $byTenant, 'alerts' => $alerts];
    }

    /**
     * Sends that failed, grouped by what the server said.
     *
     * The error string is the whole value here: fifty failures sharing one
     * message is one problem, and fifty different messages is fifty.
     */
    private function failedSends(?DateTimeImmutable $now): array
    {
        $since = Clock::toDatabase($now->modify('-24 hours'));

        $grouped = $this->rows(
            'SELECT COALESCE(failed_reason, "(no reason recorded)") AS reason,
                    status, COUNT(*) AS total, COUNT(DISTINCT tenant_id) AS tenants
             FROM chase_messages
             WHERE status IN (?, ?) AND updated_at >= ?
             GROUP BY reason, status
             ORDER BY total DESC
             LIMIT 25',
            ['failed', 'bounced', $since]
        );

        $total = array_sum(array_map(static fn (array $r): int => (int) $r['total'], $grouped));
        $alerts = [];

        if ($total > 0) {
            $alerts[] = [
                'level' => $total > 10 ? 'critical' : 'warning',
                'label' => $total . ' failed send' . ($total === 1 ? '' : 's') . ' in 24h',
                'detail' => (string) ($grouped[0]['reason'] ?? ''),
            ];
        }

        return ['total_24h' => $total, 'grouped' => $grouped, 'alerts' => $alerts];
    }

    /**
     * Mailboxes that have stopped working.
     */
    private function mailboxes(?DateTimeImmutable $now): array
    {
        $needsReauth = $this->rows(
            'SELECT e.id, e.tenant_id, o.name AS tenant_name, e.provider, e.status,
                    e.last_error, e.last_verified_at
             FROM email_accounts e
             LEFT JOIN organizations o ON o.id = e.tenant_id
             WHERE e.status = ?
             ORDER BY e.updated_at DESC LIMIT 50',
            ['needs_reauth']
        );

        $staleImap = $this->rows(
            'SELECT e.id, e.tenant_id, o.name AS tenant_name, e.imap_last_polled_at, e.imap_last_error
             FROM email_accounts e
             LEFT JOIN organizations o ON o.id = e.tenant_id
             WHERE e.imap_host IS NOT NULL AND e.imap_host <> ""
               AND (e.imap_last_polled_at IS NULL OR e.imap_last_polled_at < ?)
             ORDER BY e.imap_last_polled_at IS NULL DESC, e.imap_last_polled_at ASC
             LIMIT 50',
            [Clock::toDatabase($now->modify('-' . self::IMAP_STALE_MINUTES . ' minutes'))]
        );

        $alerts = [];

        if ($needsReauth !== []) {
            $alerts[] = [
                'level' => 'critical',
                'label' => count($needsReauth) . ' mailbox' . (count($needsReauth) === 1 ? '' : 'es') . ' need reconnecting',
                'detail' => 'Reminders are paused for these workspaces.',
            ];
        }

        if ($staleImap !== []) {
            $alerts[] = [
                'level' => 'warning',
                'label' => count($staleImap) . ' mailbox' . (count($staleImap) === 1 ? '' : 'es') . ' not polling',
                'detail' => 'Replies may not be detected, so chases will not stop when a client answers.',
            ];
        }

        return ['needs_reauth' => $needsReauth, 'stale_imap' => $staleImap, 'alerts' => $alerts];
    }

    /**
     * Stripe events that arrived and were never finished.
     */
    private function webhooks(): array
    {
        $subscriptionStuck = $this->rows(
            'SELECT status, COUNT(*) AS total, MAX(received_at) AS latest
             FROM stripe_events WHERE status IN (?, ?) GROUP BY status',
            ['received', 'failed']
        );

        $connectStuck = $this->rows(
            'SELECT status, COUNT(*) AS total, MAX(received_at) AS latest
             FROM connect_events WHERE status IN (?, ?) GROUP BY status',
            ['processing', 'failed']
        );

        $total = array_sum(array_map(
            static fn (array $r): int => (int) $r['total'],
            array_merge($subscriptionStuck, $connectStuck)
        ));

        $alerts = [];

        if ($total > 0) {
            $alerts[] = [
                'level' => 'critical',
                'label' => $total . ' unprocessed Stripe event' . ($total === 1 ? '' : 's'),
                'detail' => 'Subscriptions or payments may not have been applied.',
            ];
        }

        return ['subscription' => $subscriptionStuck, 'connect' => $connectStuck, 'alerts' => $alerts];
    }

    /**
     * Claude spend and how often workspaces are hitting the ceiling.
     */
    private function ai(?DateTimeImmutable $now): array
    {
        $since = Clock::toDatabase($now->modify('-24 hours'));

        $byOutcome = $this->rows(
            'SELECT outcome, COUNT(*) AS total FROM ai_usage
             WHERE created_at >= ? GROUP BY outcome ORDER BY total DESC',
            [$since]
        );

        $tokens = $this->rows(
            'SELECT COALESCE(SUM(input_tokens), 0) AS input,
                    COALESCE(SUM(output_tokens), 0) AS output,
                    COUNT(*) AS calls
             FROM ai_usage WHERE created_at >= ?',
            [$since]
        );

        $exhausted = (int) $this->scalar(
            'SELECT COUNT(DISTINCT tenant_id) FROM ai_usage
             WHERE created_at >= ? AND outcome = ?',
            [$since, 'budget_exhausted']
        );

        $failures = $this->rows(
            'SELECT COALESCE(failure_reason, "(none)") AS reason, COUNT(*) AS total
             FROM ai_usage WHERE created_at >= ? AND outcome <> ?
             GROUP BY reason ORDER BY total DESC LIMIT 10',
            [$since, 'success']
        );

        return [
            'by_outcome' => $byOutcome,
            'totals' => $tokens[0] ?? ['input' => 0, 'output' => 0, 'calls' => 0],
            'budget_exhausted_tenants' => $exhausted,
            'failures' => $failures,
            'alerts' => [],
        ];
    }

    // -------------------------------------------------------------- internals

    private function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . ' seconds',
            $seconds < 7200 => intdiv($seconds, 60) . ' minutes',
            default => intdiv($seconds, 3600) . ' hours',
        };
    }

    private function scalar(string $sql, array $bindings = []): mixed
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchColumn();
    }

    private function rows(string $sql, array $bindings = []): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll() ?: [];
    }
}
