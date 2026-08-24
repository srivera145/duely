<?php

namespace Keel\App\Services;

use Keel\App\Models\Chase;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\Core\Database;

/**
 * The guided first run.
 *
 * Four steps, in the order that makes them possible: a mailbox has to exist
 * before an invoice is worth chasing, an invoice has to exist before the
 * sequence means anything, and both have to exist before chasing can start.
 *
 * Progress is derived from the workspace's actual state rather than from a
 * stored cursor. Someone who imports a CSV without touching the wizard has
 * genuinely completed that step, and being told otherwise is insulting.
 */
class OnboardingService
{
    public const STEP_EMAIL = 'connect_email';
    public const STEP_INVOICE = 'add_invoice';
    public const STEP_SEQUENCE = 'review_sequence';
    public const STEP_CHASING = 'start_chasing';

    private const ORDER = [
        self::STEP_EMAIL,
        self::STEP_INVOICE,
        self::STEP_SEQUENCE,
        self::STEP_CHASING,
    ];

    /**
     * Where this workspace has got to.
     *
     * @return array{
     *     steps:array<int, array<string, mixed>>, complete:bool, skipped:bool,
     *     current:?string, completed_count:int, total:int, percent:int
     * }
     */
    public function progress(int $tenantId): array
    {
        $record = $this->record($tenantId);

        $done = [
            self::STEP_EMAIL => EmailAccount::sendingAccount($tenantId) !== null,
            self::STEP_INVOICE => Invoice::count($tenantId) > 0,
            // Reviewing is the one step with nothing to detect, so it is the
            // one the wizard actually has to remember — unless chasing is
            // already running, in which case the ladder has been accepted in
            // the only way that counts.
            self::STEP_SEQUENCE => $record['reviewed_sequence_at'] !== null
                || (Sequence::count($tenantId) > 0 && $record['started_chasing_at'] !== null),
            self::STEP_CHASING => $this->hasLiveChase($tenantId),
        ];

        $meta = [
            self::STEP_EMAIL => [
                'title' => 'Connect your email',
                'blurb' => 'Reminders go out from your own address, so replies land in your inbox.',
                'action' => 'Connect email',
                'href' => '/settings/email',
            ],
            self::STEP_INVOICE => [
                'title' => 'Add your invoices',
                'blurb' => 'Import a CSV from your spreadsheet, or add one by hand.',
                'action' => 'Import invoices',
                'href' => '/invoices/import',
            ],
            self::STEP_SEQUENCE => [
                'title' => 'Check the reminders',
                'blurb' => 'A nudge at three days, a firmer note at two weeks, a last one at a month.',
                'action' => 'Read the ladder',
                'href' => '/sequences',
            ],
            self::STEP_CHASING => [
                'title' => 'Start chasing',
                'blurb' => 'Turn it on and Duely takes it from here.',
                'action' => 'Go to invoices',
                'href' => '/invoices?status=overdue',
            ],
        ];

        $steps = [];
        $current = null;

        foreach (self::ORDER as $index => $key) {
            $isDone = (bool) $done[$key];

            // The first unfinished step is the one to nudge towards; earlier
            // steps stay reachable so nothing is a dead end.
            if (!$isDone && $current === null) {
                $current = $key;
            }

            $steps[] = array_merge($meta[$key], [
                'key' => $key,
                'number' => $index + 1,
                'done' => $isDone,
                'is_current' => false,
            ]);
        }

        foreach ($steps as $index => $step) {
            $steps[$index]['is_current'] = $step['key'] === $current;
        }

        $completed = count(array_filter($done));

        return [
            'steps' => $steps,
            'complete' => $completed === count(self::ORDER),
            'skipped' => $record['skipped_at'] !== null,
            'current' => $current,
            'completed_count' => $completed,
            'total' => count(self::ORDER),
            'percent' => (int) round(($completed / count(self::ORDER)) * 100),
        ];
    }

    /**
     * Should the wizard be shown at all?
     *
     * Not once it is finished, and not once the user has said no — but a
     * skipped wizard stays reachable from the dashboard.
     */
    public function shouldPrompt(int $tenantId): bool
    {
        $progress = $this->progress($tenantId);

        return !$progress['complete'] && !$progress['skipped'];
    }

    public function markReviewed(int $tenantId): void
    {
        $this->touch($tenantId, 'reviewed_sequence_at');
    }

    public function markSkipped(int $tenantId): void
    {
        $this->touch($tenantId, 'skipped_at');
    }

    /**
     * Come back to a wizard that was skipped earlier.
     */
    public function resume(int $tenantId): void
    {
        $this->ensureRecord($tenantId);

        $statement = Database::connection()->prepare(
            'UPDATE onboarding_progress SET skipped_at = NULL WHERE tenant_id = ?'
        );
        $statement->execute([$tenantId]);
    }

    /**
     * Record completion of whichever steps are now genuinely done.
     */
    public function sync(int $tenantId): void
    {
        $progress = $this->progress($tenantId);

        $columns = [
            self::STEP_EMAIL => 'connected_email_at',
            self::STEP_INVOICE => 'added_invoice_at',
            self::STEP_SEQUENCE => 'reviewed_sequence_at',
            self::STEP_CHASING => 'started_chasing_at',
        ];

        foreach ($progress['steps'] as $step) {
            if ($step['done']) {
                $this->touch($tenantId, $columns[$step['key']], false);
            }
        }

        if ($progress['complete']) {
            $this->touch($tenantId, 'completed_at', false);
        }
    }

    // ------------------------------------------------------------- internals

    private function hasLiveChase(int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM chases WHERE tenant_id = ? AND status IN (?, ?) LIMIT 1'
        );
        $statement->execute([$tenantId, Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE]);

        return (bool) $statement->fetchColumn();
    }

    private function record(int $tenantId): array
    {
        $this->ensureRecord($tenantId);

        $statement = Database::connection()->prepare(
            'SELECT * FROM onboarding_progress WHERE tenant_id = ? LIMIT 1'
        );
        $statement->execute([$tenantId]);

        return $statement->fetch() ?: [
            'connected_email_at' => null,
            'added_invoice_at' => null,
            'reviewed_sequence_at' => null,
            'started_chasing_at' => null,
            'skipped_at' => null,
            'completed_at' => null,
        ];
    }

    private function ensureRecord(int $tenantId): void
    {
        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO onboarding_progress (tenant_id) VALUES (?)'
        );
        $statement->execute([$tenantId]);
    }

    /**
     * Stamp a column, optionally leaving an existing value alone so the first
     * time something happened stays the recorded time.
     */
    private function touch(int $tenantId, string $column, bool $overwrite = true): void
    {
        // Column names come only from this class's own constants.
        $allowed = [
            'connected_email_at', 'added_invoice_at', 'reviewed_sequence_at',
            'started_chasing_at', 'skipped_at', 'completed_at',
        ];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        $this->ensureRecord($tenantId);

        $sql = 'UPDATE onboarding_progress SET `' . $column . '` = ? WHERE tenant_id = ?'
            . ($overwrite ? '' : ' AND `' . $column . '` IS NULL');

        $statement = Database::connection()->prepare($sql);
        $statement->execute([Clock::toDatabase(Clock::now()), $tenantId]);
    }
}
