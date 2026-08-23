<?php

namespace Keel\App\Models;

use DateTimeImmutable;

/**
 * One rung of an escalation ladder.
 *
 * `offset_days` is measured from the invoice due date, never from the send date.
 * That is what lets an invoice imported already 18 days overdue enter at the
 * right rung: we ask which steps the due date has already passed, rather than
 * starting a fresh timer at step 1.
 */
class SequenceStep extends BaseModel
{
    protected static function table(): string
    {
        return 'sequence_steps';
    }

    protected static function columns(): array
    {
        return [
            'sequence_id',
            'position',
            'offset_days',
            'subject_template',
            'body_template',
            'tone',
            'is_final',
        ];
    }

    /**
     * Steps in send order.
     */
    public static function forSequence(int $tenantId, int $sequenceId): array
    {
        $sql = 'SELECT * FROM sequence_steps
                WHERE tenant_id = ? AND sequence_id = ?
                ORDER BY position ASC, id ASC';

        return static::run($sql, [$tenantId, $sequenceId])->fetchAll();
    }

    public static function atPosition(int $tenantId, int $sequenceId, int $position): ?array
    {
        $sql = 'SELECT * FROM sequence_steps
                WHERE tenant_id = ? AND sequence_id = ? AND position = ?
                LIMIT 1';

        return static::run($sql, [$tenantId, $sequenceId, $position])->fetch() ?: null;
    }

    /**
     * The next step after the one just sent, or null when the ladder is exhausted.
     */
    public static function nextAfter(int $tenantId, int $sequenceId, int $currentPosition): ?array
    {
        $sql = 'SELECT * FROM sequence_steps
                WHERE tenant_id = ? AND sequence_id = ? AND position > ?
                ORDER BY position ASC
                LIMIT 1';

        return static::run($sql, [$tenantId, $sequenceId, $currentPosition])->fetch() ?: null;
    }

    public static function firstStep(int $tenantId, int $sequenceId): ?array
    {
        $sql = 'SELECT * FROM sequence_steps
                WHERE tenant_id = ? AND sequence_id = ?
                ORDER BY position ASC
                LIMIT 1';

        return static::run($sql, [$tenantId, $sequenceId])->fetch() ?: null;
    }

    /**
     * The rung an invoice enters at.
     *
     * Given how many days past due the invoice already is, return the last step
     * whose offset the due date has passed — that step is sent immediately and
     * the ladder continues from there. Returns null when nothing is due yet, in
     * which case the caller schedules the first step at its own offset.
     */
    public static function entryStep(int $tenantId, int $sequenceId, int $daysOverdue): ?array
    {
        $sql = 'SELECT * FROM sequence_steps
                WHERE tenant_id = ? AND sequence_id = ? AND offset_days <= ?
                ORDER BY offset_days DESC, position DESC
                LIMIT 1';

        return static::run($sql, [$tenantId, $sequenceId, $daysOverdue])->fetch() ?: null;
    }

    /**
     * The first step still in the future for an invoice this far past due.
     */
    public static function nextPendingStep(int $tenantId, int $sequenceId, int $daysOverdue): ?array
    {
        $sql = 'SELECT * FROM sequence_steps
                WHERE tenant_id = ? AND sequence_id = ? AND offset_days > ?
                ORDER BY offset_days ASC, position ASC
                LIMIT 1';

        return static::run($sql, [$tenantId, $sequenceId, $daysOverdue])->fetch() ?: null;
    }

    /**
     * Absolute send time for a step, computed from the invoice due date.
     */
    public static function sendAtFor(array $step, string $dueDate, string $sendHour = '09:00:00'): DateTimeImmutable
    {
        $due = new DateTimeImmutable($dueDate . ' ' . $sendHour);
        $offset = (int) $step['offset_days'];
        $interval = 'P' . abs($offset) . 'D';

        return $offset >= 0 ? $due->add(new \DateInterval($interval)) : $due->sub(new \DateInterval($interval));
    }

    /**
     * Rewrite step order in one transaction so `uniq_sequence_steps_position`
     * never sees a duplicate mid-flight.
     *
     * @param int[] $orderedStepIds
     */
    public static function reorder(int $tenantId, int $sequenceId, array $orderedStepIds): bool
    {
        $connection = \Keel\Core\Database::connection();
        $connection->beginTransaction();

        try {
            $park = $connection->prepare(
                'UPDATE sequence_steps SET position = -position
                 WHERE tenant_id = ? AND sequence_id = ? AND position > 0'
            );
            $park->execute([$tenantId, $sequenceId]);

            $assign = $connection->prepare(
                'UPDATE sequence_steps SET position = ?
                 WHERE tenant_id = ? AND sequence_id = ? AND id = ?'
            );

            $position = 1;
            foreach ($orderedStepIds as $stepId) {
                $assign->execute([$position, $tenantId, $sequenceId, (int) $stepId]);
                $position++;
            }

            $connection->commit();

            return true;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }
}
