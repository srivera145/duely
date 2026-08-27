<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Database;
use Keel\Core\Session;

/**
 * Signing in as a customer to see what they see.
 *
 * Four decisions hold this together, and none of them is optional.
 *
 * **It never mints a normal user session.** `Session::get('user_id')` keeps
 * holding the operator's own id for the whole of an impersonated session. What
 * changes is that a session key points at a row in `impersonation_sessions`, and
 * the impersonated identity is resolved from that row on every request. So the
 * session cannot outlive the row, ending it is a database write rather than a
 * hope that a cookie got cleared, and `realUserId()` always has a true answer to
 * "who is actually doing this".
 *
 * **It is read-only, enforced in middleware.** The blocked actions are listed in
 * ImpersonationGuardMiddleware and keyed off the session, not off which buttons
 * a template chose to render. A hidden button is not a control: it is a
 * suggestion that anybody with a terminal can decline.
 *
 * **It expires hard at thirty minutes.** There is no renewal. Carrying on means
 * a new session with a new reason, which gets recorded like the first one — the
 * point being that a long investigation leaves a trail proportional to its
 * length, rather than one entry from four hours ago.
 *
 * **It cannot reach the panel.** Escalating back out of an impersonated session
 * into super-admin would let the operator use the customer's session as a
 * springboard, and would make the audit trail describe the wrong actor.
 */
class ImpersonationService
{
    /** Points at an impersonation_sessions row. Not a user id. */
    public const SESSION_KEY = 'impersonation_id';

    /** Hard limit. No renewal, deliberately. */
    public const MAX_MINUTES = 30;

    public function __construct(private readonly SupportAccessLog $log = new SupportAccessLog())
    {
    }

    /**
     * Begin a session.
     *
     * @return array{ok:bool, error:?string, id:?int}
     */
    public function start(
        int $impersonatorUserId,
        int $targetUserId,
        string $reason,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= Clock::now();

        if (!SupportAccessLog::isUsableReason($reason)) {
            return $this->failure(
                'Give a reason of at least ' . SupportAccessLog::MIN_REASON_LENGTH . ' characters.'
            );
        }

        $target = $this->user($targetUserId);

        if ($target === null) {
            return $this->failure('No such user.');
        }

        // Impersonating another operator would produce a session whose audit
        // trail names two people with the same powers and no way to tell which
        // one acted.
        if ((int) ($target['is_super_admin'] ?? 0) === 1) {
            return $this->failure('Super-admin accounts cannot be impersonated.');
        }

        if ((int) $target['id'] === $impersonatorUserId) {
            return $this->failure('That is already you.');
        }

        $tenantId = $target['organization_id'] === null ? null : (int) $target['organization_id'];

        $statement = Database::connection()->prepare(
            'INSERT INTO impersonation_sessions
                (impersonator_user_id, target_user_id, tenant_id, reason, started_at, expires_at, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $statement->execute([
            $impersonatorUserId,
            (int) $target['id'],
            $tenantId,
            mb_substr(trim($reason), 0, 500),
            Clock::toDatabase($now),
            Clock::toDatabase($now->modify('+' . self::MAX_MINUTES . ' minutes')),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $id = (int) Database::connection()->lastInsertId();

        Session::put(self::SESSION_KEY, $id);

        if ($tenantId !== null) {
            // Written before the operator sees a single page of the account, so
            // the customer's feed shows the access even if the session then
            // dies mid-request.
            $this->log->recordImpersonation(
                'impersonation.started',
                $tenantId,
                (int) $target['id'],
                $reason,
                ['target_email' => (string) $target['email'], 'expires_in_minutes' => self::MAX_MINUTES]
            );
        }

        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /**
     * The live session, or null. Expiry is checked here rather than by a job:
     * a session that has run out is over the moment somebody asks, with no
     * window where a sweep has not run yet.
     *
     * @return array<string, mixed>|null
     */
    public function current(?DateTimeImmutable $now = null): ?array
    {
        $id = (int) Session::get(self::SESSION_KEY, 0);

        if ($id <= 0) {
            return null;
        }

        $now ??= Clock::now();

        $statement = Database::connection()->prepare(
            'SELECT s.*, u.name AS target_name, u.email AS target_email, u.organization_id AS target_org
             FROM impersonation_sessions s
             INNER JOIN users u ON u.id = s.target_user_id
             WHERE s.id = ? AND s.ended_at IS NULL AND s.expires_at > ?
             LIMIT 1'
        );
        $statement->execute([$id, Clock::toDatabase($now)]);
        $row = $statement->fetch();

        if (!$row) {
            // Expired or ended. Clear the pointer so every later request on
            // this browser is an ordinary operator request again.
            Session::forget(self::SESSION_KEY);

            return null;
        }

        return $row;
    }

    public function isActive(?DateTimeImmutable $now = null): bool
    {
        return $this->current($now) !== null;
    }

    /**
     * End it.
     */
    public function stop(?DateTimeImmutable $now = null): void
    {
        $id = (int) Session::get(self::SESSION_KEY, 0);
        Session::forget(self::SESSION_KEY);

        if ($id <= 0) {
            return;
        }

        $now ??= Clock::now();

        // ended_at only, and only while it is null. The row itself is the
        // record that this happened; nothing rewrites the reason or the times.
        $statement = Database::connection()->prepare(
            'UPDATE impersonation_sessions SET ended_at = ? WHERE id = ? AND ended_at IS NULL'
        );
        $statement->execute([Clock::toDatabase($now), $id]);

        $lookup = Database::connection()->prepare(
            'SELECT tenant_id, target_user_id, reason FROM impersonation_sessions WHERE id = ? LIMIT 1'
        );
        $lookup->execute([$id]);
        $row = $lookup->fetch();

        if ($row && $row['tenant_id'] !== null) {
            $this->log->recordImpersonation(
                'impersonation.ended',
                (int) $row['tenant_id'],
                (int) $row['target_user_id'],
                (string) $row['reason']
            );
        }
    }

    /**
     * The id the application should treat as the signed-in user.
     *
     * Everything that reads "who is this" goes through here, so there is one
     * place where impersonation is resolved and no second place to forget.
     */
    public static function effectiveUserId(): ?int
    {
        $session = (new self())->current();

        if ($session !== null) {
            return (int) $session['target_user_id'];
        }

        $id = Session::get('user_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The human actually at the keyboard, impersonation or not.
     *
     * The audit trail uses this. An entry naming the impersonated customer for
     * something the operator did would be worse than no entry at all.
     */
    public static function realUserId(): ?int
    {
        $id = Session::get('user_id');

        return $id === null ? null : (int) $id;
    }

    // -------------------------------------------------------------- internals

    private function user(int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, name, email, organization_id, is_super_admin FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$userId]);

        return $statement->fetch() ?: null;
    }

    private function failure(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'id' => null];
    }
}
