<?php

namespace Keel\App\Models;

use DateTimeImmutable;
use Keel\App\Services\Crypto;

/**
 * The mailbox Duely sends from and reads replies out of.
 *
 * Secrets never reach the database in plaintext. Callers pass plaintext under
 * the logical keys (`smtp_password`, `imap_password`, `oauth_access_token`,
 * `oauth_refresh_token`); this model encrypts them into the `*_encrypted`
 * columns on the way in, and decryption is opt-in through the explicit
 * accessors below so an accidental row dump stays inert.
 */
class EmailAccount extends BaseModel
{
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';
    public const STATUS_DISABLED = 'disabled';

    /**
     * Plaintext input key => encrypted storage column.
     */
    private const SECRET_MAP = [
        'smtp_password' => 'smtp_password_encrypted',
        'imap_password' => 'imap_password_encrypted',
        'oauth_access_token' => 'oauth_access_token_encrypted',
        'oauth_refresh_token' => 'oauth_refresh_token_encrypted',
    ];

    protected static function table(): string
    {
        return 'email_accounts';
    }

    protected static function columns(): array
    {
        return [
            'user_id',
            'provider',
            'from_name',
            'from_email',
            'reply_to',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password_encrypted',
            'imap_host',
            'imap_port',
            'imap_encryption',
            'imap_username',
            'imap_password_encrypted',
            'imap_folder',
            'imap_last_seen_uid',
            'imap_last_polled_at',
            'oauth_access_token_encrypted',
            'oauth_refresh_token_encrypted',
            'oauth_expires_at',
            'status',
            'last_verified_at',
            'last_error',
            'is_default',
        ];
    }

    public static function create(int $tenantId, array $attributes): int
    {
        return parent::create($tenantId, static::sealSecrets($attributes));
    }

    public static function update(int $tenantId, int $id, array $attributes): bool
    {
        return parent::update($tenantId, $id, static::sealSecrets($attributes));
    }

    // ------------------------------------------------------------- accessors

    public static function smtpPassword(array $row): ?string
    {
        return Crypto::decryptNullable($row['smtp_password_encrypted'] ?? null);
    }

    public static function imapPassword(array $row): ?string
    {
        return Crypto::decryptNullable($row['imap_password_encrypted'] ?? null);
    }

    public static function oauthAccessToken(array $row): ?string
    {
        return Crypto::decryptNullable($row['oauth_access_token_encrypted'] ?? null);
    }

    public static function oauthRefreshToken(array $row): ?string
    {
        return Crypto::decryptNullable($row['oauth_refresh_token_encrypted'] ?? null);
    }

    /**
     * A row safe to hand to a view or JSON response: ciphertext columns stripped.
     */
    public static function redact(array $row): array
    {
        foreach (self::SECRET_MAP as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    // --------------------------------------------------------------- queries

    public static function findByEmail(int $tenantId, string $fromEmail): ?array
    {
        return static::findOneBy($tenantId, 'from_email', strtolower(trim($fromEmail)));
    }

    public static function defaultAccount(int $tenantId): ?array
    {
        $sql = 'SELECT * FROM email_accounts
                WHERE tenant_id = ? AND is_default = 1
                LIMIT 1';

        return static::run($sql, [$tenantId])->fetch() ?: null;
    }

    /**
     * The account to send with: the default if it is usable, else any active one.
     */
    public static function sendingAccount(int $tenantId): ?array
    {
        $sql = 'SELECT * FROM email_accounts
                WHERE tenant_id = ? AND status = ?
                ORDER BY is_default DESC, id ASC
                LIMIT 1';

        return static::run($sql, [$tenantId, self::STATUS_ACTIVE])->fetch() ?: null;
    }

    public static function active(int $tenantId): array
    {
        $sql = 'SELECT * FROM email_accounts
                WHERE tenant_id = ? AND status = ?
                ORDER BY is_default DESC, from_email ASC';

        return static::run($sql, [$tenantId, self::STATUS_ACTIVE])->fetchAll();
    }

    /**
     * Accounts with IMAP configured, for the reply-polling job.
     */
    public static function pollable(int $tenantId): array
    {
        $sql = 'SELECT * FROM email_accounts
                WHERE tenant_id = ?
                  AND status = ?
                  AND imap_host IS NOT NULL
                  AND imap_username IS NOT NULL
                ORDER BY imap_last_polled_at IS NULL DESC, imap_last_polled_at ASC';

        return static::run($sql, [$tenantId, self::STATUS_ACTIVE])->fetchAll();
    }

    // ---------------------------------------------------------- state changes

    /**
     * Promote one account to default. The partial unique index allows a single
     * default per tenant, so the demotion and promotion run in one transaction.
     */
    public static function makeDefault(int $tenantId, int $id): bool
    {
        $connection = \Keel\Core\Database::connection();
        $connection->beginTransaction();

        try {
            $demote = $connection->prepare(
                'UPDATE email_accounts SET is_default = 0 WHERE tenant_id = ? AND id <> ?'
            );
            $demote->execute([$tenantId, $id]);

            $promote = $connection->prepare(
                'UPDATE email_accounts SET is_default = 1 WHERE tenant_id = ? AND id = ?'
            );
            $promote->execute([$tenantId, $id]);
            $promoted = $promote->rowCount() > 0;

            $connection->commit();

            return $promoted;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    public static function markVerified(int $tenantId, int $id): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_ACTIVE,
            'last_verified_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_error' => null,
        ]);
    }

    public static function markNeedsReauth(int $tenantId, int $id, string $reason): bool
    {
        return static::update($tenantId, $id, [
            'status' => self::STATUS_NEEDS_REAUTH,
            'last_error' => $reason,
        ]);
    }

    public static function recordPoll(int $tenantId, int $id, ?int $lastSeenUid = null): bool
    {
        $attributes = ['imap_last_polled_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s')];

        if ($lastSeenUid !== null) {
            $attributes['imap_last_seen_uid'] = $lastSeenUid;
        }

        return static::update($tenantId, $id, $attributes);
    }

    // ---------------------------------------------------------------- private

    /**
     * Replace plaintext secret keys with their encrypted columns. Any ciphertext
     * column supplied directly by a caller is discarded first, so plaintext keys
     * are the only route in and nothing can be written unencrypted by mistake.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function sealSecrets(array $attributes): array
    {
        foreach (self::SECRET_MAP as $plainKey => $column) {
            unset($attributes[$column]);

            if (!array_key_exists($plainKey, $attributes)) {
                continue;
            }

            $plaintext = $attributes[$plainKey];
            unset($attributes[$plainKey]);

            $attributes[$column] = $plaintext === null
                ? null
                : Crypto::encrypt((string) $plaintext);
        }

        return $attributes;
    }
}
