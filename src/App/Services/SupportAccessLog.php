<?php

namespace Keel\App\Services;

use Keel\Core\Activity;
use Keel\Core\Auth;
use Keel\Core\Database;
use Throwable;

/**
 * The record of what the operator did.
 *
 * This class only ever inserts. There is no update method, no delete method, and
 * no method that takes an id — not because one would be hard to write, but
 * because the person this table audits is the same person who deploys the code.
 * A trail its subject can edit is not a trail, and the cheapest way to keep that
 * true is to give the codebase no vocabulary for it.
 *
 * Two writes happen for anything touching a tenant:
 *
 *   `support_access_log` — the operator's own audit, which nothing deletes.
 *   `activity_log`       — the customer's feed, so the account owner sees the
 *                          access in their own account without asking and
 *                          without us choosing to tell them.
 *
 * The second is what makes this defensible rather than surveillance, and the
 * privacy page promises it by name.
 */
class SupportAccessLog
{
    public const KIND_VIEW = 'view';
    public const KIND_ACTION = 'action';
    public const KIND_IMPERSONATION = 'impersonation';

    /** A reason shorter than this is not a reason. */
    public const MIN_REASON_LENGTH = 10;

    /**
     * A page the operator opened.
     *
     * Reads are logged as seriously as writes. In a support tool the read *is*
     * the sensitive act: nobody worries about the operator changing their
     * invoice number, they worry about the operator reading it.
     */
    public function recordView(string $action, ?int $tenantId = null, array $metadata = []): void
    {
        $this->write(self::KIND_VIEW, $action, $tenantId, null, null, $metadata);
    }

    /**
     * A change the operator made.
     */
    public function recordAction(
        string $action,
        ?int $tenantId = null,
        array $metadata = [],
        ?string $reason = null
    ): void {
        $this->write(self::KIND_ACTION, $action, $tenantId, null, $reason, $metadata);
    }

    /**
     * Opening a tenant's data, or starting or ending an impersonation session.
     */
    public function recordImpersonation(
        string $action,
        int $tenantId,
        int $targetUserId,
        string $reason,
        array $metadata = []
    ): void {
        $this->write(self::KIND_IMPERSONATION, $action, $tenantId, $targetUserId, $reason, $metadata);
    }

    /**
     * Is this a reason, or is it someone typing "asdf" to get past the box?
     */
    public static function isUsableReason(?string $reason): bool
    {
        return mb_strlen(trim((string) $reason)) >= self::MIN_REASON_LENGTH;
    }

    /**
     * What one tenant's owner should be able to read about access to their own
     * account. Scoped to the tenant, and it carries the reason: an entry saying
     * only "support looked at your account" is worse than none.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forTenant(int $tenantId, int $limit = 50): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, super_admin_email, kind, action, reason, created_at
             FROM support_access_log
             WHERE tenant_id = ?
             ORDER BY id DESC
             LIMIT ' . max(1, min($limit, 200))
        );
        $statement->execute([$tenantId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * The operator's own view of the trail.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100, ?int $tenantId = null): array
    {
        $sql = 'SELECT * FROM support_access_log';
        $bindings = [];

        if ($tenantId !== null) {
            $sql .= ' WHERE tenant_id = ?';
            $bindings[] = $tenantId;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min($limit, 500));

        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll() ?: [];
    }

    // -------------------------------------------------------------- internals

    private function write(
        string $kind,
        string $action,
        ?int $tenantId,
        ?int $targetUserId,
        ?string $reason,
        array $metadata
    ): void {
        $admin = $this->actingAdmin();

        if ($admin === null) {
            // No attributable operator means no entry to write, and something
            // has gone wrong upstream: every path here is behind the
            // super-admin middleware. Loud in the log, silent to the user.
            error_log('[Duely] Support access could not be attributed: ' . $action);

            return;
        }

        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO support_access_log
                    (super_admin_user_id, super_admin_email, tenant_id, target_user_id,
                     kind, action, reason, metadata, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                (int) $admin['id'],
                // Denormalised on purpose. The email at the time of access is
                // the fact being recorded; a join would rewrite history if the
                // operator later changed their address or the row went away.
                (string) $admin['email'],
                $tenantId,
                $targetUserId,
                $kind,
                mb_substr($action, 0, 100),
                $reason === null ? null : mb_substr(trim($reason), 0, 500),
                $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                $_SERVER['REMOTE_ADDR'] ?? null,
                Clock::toDatabase(Clock::now()),
            ]);
        } catch (Throwable $exception) {
            // An audit write that fails must be noisy. It must not, however,
            // take down the request in a way that could be used to probe the
            // panel -- the caller decides what to do about the failure.
            error_log('[Duely] Support access log write failed: ' . $exception->getMessage());
        }

        $this->mirrorToTenantFeed($kind, $action, $tenantId, $reason, $admin);
    }

    /**
     * The customer's copy.
     *
     * Only for access aimed at a specific account. A platform-wide page view
     * has no tenant to tell, and putting it in everybody's feed would be noise
     * that buries the entries that matter.
     */
    private function mirrorToTenantFeed(
        string $kind,
        string $action,
        ?int $tenantId,
        ?string $reason,
        array $admin
    ): void {
        if ($tenantId === null) {
            return;
        }

        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO activity_log
                    (user_id, organization_id, action, subject_type, subject_id, metadata, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                (int) $admin['id'],
                $tenantId,
                'support.' . $kind,
                'Organization',
                $tenantId,
                json_encode([
                    'detail' => $action,
                    // The reason is the point. Without it the entry says
                    // somebody looked and refuses to say why, which reads worse
                    // than saying nothing.
                    'reason' => $reason,
                    'by' => (string) $admin['email'],
                ], JSON_THROW_ON_ERROR),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $exception) {
            error_log('[Duely] Support access mirror failed: ' . $exception->getMessage());
        }
    }

    /**
     * The real operator, never the impersonated identity.
     */
    private function actingAdmin(): ?array
    {
        $userId = ImpersonationService::realUserId() ?? Auth::id();

        if (!$userId) {
            return null;
        }

        $statement = Database::connection()->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
        $statement->execute([(int) $userId]);

        return $statement->fetch() ?: null;
    }
}
