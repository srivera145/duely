<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * A named ladder of reminders — the escalation a tenant applies to an invoice.
 */
class Sequence extends BaseModel
{
    protected static function table(): string
    {
        return 'sequences';
    }

    protected static function columns(): array
    {
        return [
            'name',
            'description',
            'tone',
            'send_window_start',
            'send_window_end',
            'skip_weekends',
            'is_active',
            'is_default',
        ];
    }

    public static function findByName(int $tenantId, string $name): ?array
    {
        return static::findOneBy($tenantId, 'name', trim($name));
    }

    public static function active(int $tenantId): array
    {
        $sql = 'SELECT * FROM sequences
                WHERE tenant_id = ? AND is_active = 1
                ORDER BY is_default DESC, name ASC';

        return static::run($sql, [$tenantId])->fetchAll();
    }

    public static function defaultSequence(int $tenantId): ?array
    {
        $sql = 'SELECT * FROM sequences
                WHERE tenant_id = ? AND is_default = 1 AND is_active = 1
                LIMIT 1';

        return static::run($sql, [$tenantId])->fetch() ?: null;
    }

    /**
     * The sequence plus its steps in send order.
     */
    public static function withSteps(int $tenantId, int $id): ?array
    {
        $sequence = static::find($tenantId, $id);

        if ($sequence === null) {
            return null;
        }

        $sequence['steps'] = SequenceStep::forSequence($tenantId, $id);

        return $sequence;
    }

    /**
     * Sequences with a step count, for the index screen.
     */
    public static function withStepCounts(int $tenantId): array
    {
        $sql = 'SELECT s.*, COUNT(st.id) AS step_count
                FROM sequences s
                LEFT JOIN sequence_steps st
                       ON st.sequence_id = s.id
                      AND st.tenant_id = s.tenant_id
                WHERE s.tenant_id = ?
                GROUP BY s.id
                ORDER BY s.is_default DESC, s.name ASC';

        return static::run($sql, [$tenantId])->fetchAll();
    }

    /**
     * How many chases currently reference this sequence. Guards deletion.
     */
    public static function activeChaseCount(int $tenantId, int $id): int
    {
        $sql = 'SELECT COUNT(*) FROM chases
                WHERE tenant_id = ? AND sequence_id = ? AND status IN (?, ?, ?)';

        return (int) static::run($sql, [
            $tenantId,
            $id,
            Chase::STATUS_SCHEDULED,
            Chase::STATUS_ACTIVE,
            Chase::STATUS_PAUSED,
        ])->fetchColumn();
    }

    /**
     * Promote one sequence to default; the partial unique index permits exactly
     * one per tenant, so demotion and promotion share a transaction.
     */
    public static function makeDefault(int $tenantId, int $id): bool
    {
        $connection = Database::connection();
        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            $demote = $connection->prepare(
                'UPDATE sequences SET is_default = 0 WHERE tenant_id = ? AND id <> ?'
            );
            $demote->execute([$tenantId, $id]);

            $promote = $connection->prepare(
                'UPDATE sequences SET is_default = 1, is_active = 1 WHERE tenant_id = ? AND id = ?'
            );
            $promote->execute([$tenantId, $id]);
            $promoted = $promote->rowCount() > 0;

            if ($openedTransaction) {
                $connection->commit();
            }

            return $promoted;
        } catch (\Throwable $exception) {
            if ($openedTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Create a sequence and its steps atomically.
     *
     * @param array<int, array<string, mixed>> $steps step attributes in send order
     */
    public static function createWithSteps(int $tenantId, array $attributes, array $steps): int
    {
        $connection = Database::connection();
        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            $sequenceId = static::create($tenantId, $attributes);

            $position = 1;
            foreach ($steps as $step) {
                $step['sequence_id'] = $sequenceId;
                $step['position'] = $step['position'] ?? $position;
                SequenceStep::create($tenantId, $step);
                $position++;
            }

            if ($openedTransaction) {
                $connection->commit();
            }

            return $sequenceId;
        } catch (\Throwable $exception) {
            if ($openedTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }
}
