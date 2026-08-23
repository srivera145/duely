<?php

namespace Keel\App\Services;

use Keel\App\Models\Sequence;
use Keel\App\Models\SequenceStep;
use Keel\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Gives every new tenant the default escalation ladder.
 *
 * A tenant with no sequence cannot chase anything, so seeding runs on both
 * paths into a workspace: OrganizationService::create() when multi-tenancy is
 * on, and TenantContext when a solo user gets a personal workspace.
 *
 * Seeding is idempotent — a tenant that already has a sequence is left alone,
 * because by then the copy is theirs and may have been edited.
 */
class SequenceSeeder
{
    /**
     * Seed the default sequence unless this tenant already has one.
     *
     * @return int|null the new sequence id, or null when nothing was needed
     */
    public static function seed(int $tenantId): ?int
    {
        if (Sequence::count($tenantId) > 0) {
            return null;
        }

        $definition = self::definition();
        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            $sequenceId = Sequence::create($tenantId, [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'tone' => $definition['tone'],
                'send_window_start' => $definition['send_window_start'],
                'send_window_end' => $definition['send_window_end'],
                'skip_weekends' => $definition['skip_weekends'],
                'is_active' => 1,
                'is_default' => 1,
            ]);

            foreach ($definition['steps'] as $step) {
                SequenceStep::create($tenantId, [
                    'sequence_id' => $sequenceId,
                    'position' => $step['position'],
                    'offset_days' => $step['offset_days'],
                    'tone' => $step['tone'],
                    'is_final' => $step['is_final'],
                    'subject_template' => $step['subject_template'],
                    'body_template' => $step['body_template'],
                ]);
            }

            $connection->commit();

            return $sequenceId;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Seeding must never be the thing that stops someone signing up.
     *
     * A tenant without a sequence is recoverable — the sequences screen offers
     * to restore the default — whereas a failed signup is not.
     */
    public static function seedQuietly(int $tenantId): ?int
    {
        try {
            return self::seed($tenantId);
        } catch (Throwable $exception) {
            error_log('[Duely] Could not seed the default sequence for tenant ' . $tenantId . ': ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * The ladder definition, loaded from the seed file so the copy lives in one
     * editable place rather than inside this class.
     */
    public static function definition(): array
    {
        $path = dirname(__DIR__, 3) . '/database/seeds/default_sequence.php';

        if (!is_file($path)) {
            throw new RuntimeException('The default sequence seed is missing: ' . $path);
        }

        $definition = require $path;

        if (!is_array($definition) || !isset($definition['steps']) || !is_array($definition['steps'])) {
            throw new RuntimeException('The default sequence seed did not return a usable definition.');
        }

        return $definition;
    }
}
