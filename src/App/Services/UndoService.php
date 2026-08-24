<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\App\Models\Invoice;
use Keel\Core\Auth;
use Keel\Core\Database;
use Throwable;

/**
 * Makes a state change reversible for a short window.
 *
 * "Mark paid" is a single click that stops a live chase, and the most common
 * mistake with a list of invoices is ticking the wrong row. Rather than
 * reconstructing the previous state later — by which time a worker may have
 * advanced the chase — the exact prior values are snapshotted at the moment of
 * the change and restored verbatim.
 */
class UndoService
{
    /** How long an action stays reversible. */
    public const WINDOW_SECONDS = 30;

    public const ACTION_MARK_PAID = 'invoice.mark_paid';

    /**
     * Record what a change replaced, and hand back a token to reverse it.
     */
    public function remember(
        int $tenantId,
        string $action,
        string $subjectType,
        int $subjectId,
        array $snapshot,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= Clock::now();
        $token = bin2hex(random_bytes(16));

        $this->sweep($now);

        $statement = Database::connection()->prepare(
            'INSERT INTO undo_actions (tenant_id, user_id, token, action, subject_type, subject_id, snapshot, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $statement->execute([
            $tenantId,
            Auth::id(),
            $token,
            $action,
            $subjectType,
            $subjectId,
            json_encode($snapshot, JSON_THROW_ON_ERROR),
            Clock::toDatabase($now->modify('+' . self::WINDOW_SECONDS . ' seconds')),
        ]);

        return [
            'token' => $token,
            'expires_in' => self::WINDOW_SECONDS,
        ];
    }

    /**
     * Reverse a remembered action.
     *
     * The token is looked up under the caller's tenant, so a token from
     * another workspace resolves to nothing rather than to their invoice.
     *
     * @return array{undone:bool, reason:?string, subject_id:?int}
     */
    public function undo(int $tenantId, string $token, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return ['undone' => false, 'reason' => 'That undo link is not valid.', 'subject_id' => null];
        }

        $connection = Database::connection();
        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            // Locked so two clicks cannot both consume the same token.
            $select = $connection->prepare(
                'SELECT * FROM undo_actions
                 WHERE tenant_id = ? AND token = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $select->execute([$tenantId, $token]);
            $record = $select->fetch();

            if (!$record) {
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['undone' => false, 'reason' => 'There is nothing to undo.', 'subject_id' => null];
            }

            if ($record['used_at'] !== null) {
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['undone' => false, 'reason' => 'That has already been undone.', 'subject_id' => (int) $record['subject_id']];
            }

            if (Clock::fromDatabase($record['expires_at']) < $now) {
                if ($openedTransaction) {
                    $connection->commit();
                }

                return [
                    'undone' => false,
                    'reason' => 'The undo window has passed. You can change it back by hand.',
                    'subject_id' => (int) $record['subject_id'],
                ];
            }

            $snapshot = json_decode((string) $record['snapshot'], true);

            if (!is_array($snapshot)) {
                if ($openedTransaction) {
                    $connection->commit();
                }

                return ['undone' => false, 'reason' => 'That change cannot be reversed.', 'subject_id' => null];
            }

            $this->restore($tenantId, (string) $record['action'], (int) $record['subject_id'], $snapshot);

            $consume = $connection->prepare('UPDATE undo_actions SET used_at = ? WHERE id = ?');
            $consume->execute([Clock::toDatabase($now), (int) $record['id']]);

            if ($openedTransaction) {
                $connection->commit();
            }

            return ['undone' => true, 'reason' => null, 'subject_id' => (int) $record['subject_id']];
        } catch (Throwable $exception) {
            if ($openedTransaction && $connection->inTransaction()) {
                    $connection->rollBack();
                }

            throw $exception;
        }
    }

    /**
     * Put the snapshotted values back.
     */
    private function restore(int $tenantId, string $action, int $subjectId, array $snapshot): void
    {
        if ($action !== self::ACTION_MARK_PAID) {
            return;
        }

        Invoice::update($tenantId, $subjectId, [
            'status' => $snapshot['invoice']['status'] ?? Invoice::STATUS_OPEN,
            'paid_at' => $snapshot['invoice']['paid_at'] ?? null,
            'paid_source' => $snapshot['invoice']['paid_source'] ?? null,
        ]);

        $chase = $snapshot['chase'] ?? null;

        if ($chase === null || empty($chase['id'])) {
            return;
        }

        // Restore the chase exactly as it was, including where it had got to
        // and when it was next due to send.
        Chase::update($tenantId, (int) $chase['id'], [
            'status' => $chase['status'],
            'paused_reason' => $chase['paused_reason'],
            'paused_at' => $chase['paused_at'],
            'next_send_at' => $chase['next_send_at'],
            'current_position' => (int) $chase['current_position'],
        ]);
    }

    /**
     * Drop expired tokens. Cheap, and keeps the table from growing forever.
     */
    public function sweep(?DateTimeImmutable $now = null): int
    {
        $now ??= Clock::now();

        $statement = Database::connection()->prepare(
            'DELETE FROM undo_actions WHERE expires_at < ? LIMIT 500'
        );
        $statement->execute([Clock::toDatabase($now->modify('-1 hour'))]);

        return $statement->rowCount();
    }
}
