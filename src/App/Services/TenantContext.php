<?php

namespace Keel\App\Services;

use Keel\App\Models\User;
use Keel\Core\Auth;
use Keel\Core\Database;
use RuntimeException;

/**
 * Resolves the tenant id every Duely model call requires.
 *
 * Keel treats organizations as optional — MULTI_TENANCY_ENABLED can be off and
 * `users.organization_id` can be null. Duely cannot: every client, invoice and
 * chase is tenant-owned. So a solo user gets a personal organization created on
 * first use, and single-tenant and multi-tenant installs take the same path
 * through the models.
 */
class TenantContext
{
    /**
     * The current user's tenant id, provisioning one if this is their first visit.
     */
    public static function requireId(): int
    {
        $userId = Auth::id();

        if ($userId === null) {
            throw new RuntimeException('Cannot resolve a tenant without an authenticated user.');
        }

        return self::forUser($userId);
    }

    public static function forUser(int $userId): int
    {
        $user = User::find($userId);

        if ($user === null) {
            throw new RuntimeException('Authenticated user could not be loaded.');
        }

        if (!empty($user['organization_id'])) {
            return (int) $user['organization_id'];
        }

        return self::provisionPersonalOrganization($user);
    }

    /**
     * The authenticated user row, for defaulting send-as name and reply-to.
     */
    public static function user(): array
    {
        $userId = Auth::id();

        if ($userId === null) {
            throw new RuntimeException('No authenticated user.');
        }

        return User::find($userId) ?? throw new RuntimeException('Authenticated user could not be loaded.');
    }

    /**
     * Create a workspace for a user who has none, and attach them to it.
     */
    private static function provisionPersonalOrganization(array $user): int
    {
        $connection = Database::connection();
        $name = trim((string) ($user['name'] ?? '')) ?: (string) $user['email'];
        $slug = self::uniqueSlug($name);

        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            $insert = $connection->prepare('INSERT INTO organizations (name, slug) VALUES (?, ?)');
            $insert->execute([$name, $slug]);
            $organizationId = (int) $connection->lastInsertId();

            $attach = $connection->prepare('UPDATE users SET organization_id = ?, role = ? WHERE id = ?');
            $attach->execute([$organizationId, 'owner', (int) $user['id']]);

            if ($openedTransaction) {
                $connection->commit();
            }

            // A workspace without a sequence cannot chase anything, so the
            // default ladder is part of creating one. Seeding failure is
            // logged rather than raised: losing the sequence is recoverable,
            // losing the signup is not.
            SequenceSeeder::seedQuietly($organizationId);

            return $organizationId;
        } catch (\Throwable $exception) {
            // The commit above may already have landed, so only roll back a
            // transaction that is genuinely still open.
            if ($openedTransaction && $connection->inTransaction()) {
                    $connection->rollBack();
                }

            throw $exception;
        }
    }

    private static function uniqueSlug(string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $base = $base !== '' ? $base : 'workspace';

        return substr($base, 0, 200) . '-' . bin2hex(random_bytes(4));
    }
}
