<?php

namespace Keel\App\Models;

/**
 * A person Duely sends invoices and follow-ups to.
 */
class Client extends BaseModel
{
    protected static function table(): string
    {
        return 'clients';
    }

    protected static function columns(): array
    {
        return [
            'name',
            'email',
            'company',
            'phone',
            'timezone',
            'notes',
            'is_archived',
            'suppressed_at',
            'suppressed_reason',
            'email_invalid_at',
            'email_invalid_reason',
        ];
    }

    public static function findByEmail(int $tenantId, string $email): ?array
    {
        return static::findOneBy($tenantId, 'email', strtolower(trim($email)));
    }

    /**
     * Active clients, alphabetical. Archived clients stay queryable via all().
     */
    public static function active(int $tenantId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM clients
                WHERE tenant_id = ? AND is_archived = 0
                ORDER BY name ASC, id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId], $limit, $offset)->fetchAll();
    }

    public static function search(int $tenantId, string $term, int $limit = 25): array
    {
        $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($term)) . '%';

        $sql = 'SELECT * FROM clients
                WHERE tenant_id = ?
                  AND (name LIKE ? OR email LIKE ? OR company LIKE ?)
                ORDER BY name ASC, id ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId, $needle, $needle, $needle], $limit, 0)->fetchAll();
    }

    /**
     * Upsert on (tenant_id, email) so repeated CSV imports do not duplicate people.
     */
    public static function findOrCreate(int $tenantId, string $email, array $attributes = []): int
    {
        $email = strtolower(trim($email));
        $existing = static::findByEmail($tenantId, $email);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $attributes['email'] = $email;
        $attributes['name'] = $attributes['name'] ?? $email;

        return static::create($tenantId, $attributes);
    }

    public static function archive(int $tenantId, int $id): bool
    {
        return static::update($tenantId, $id, ['is_archived' => 1]);
    }

    public static function unarchive(int $tenantId, int $id): bool
    {
        return static::update($tenantId, $id, ['is_archived' => 0]);
    }

    /**
     * Client rows plus their open-invoice rollup, for the clients index.
     */
    public static function withOutstanding(int $tenantId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT c.*,
                       COUNT(i.id) AS open_invoice_count,
                       COALESCE(SUM(i.amount_cents), 0) AS outstanding_cents
                FROM clients c
                LEFT JOIN invoices i
                       ON i.client_id = c.id
                      AND i.tenant_id = c.tenant_id
                      AND i.status = ?
                WHERE c.tenant_id = ? AND c.is_archived = 0
                GROUP BY c.id
                ORDER BY outstanding_cents DESC, c.name ASC
                LIMIT ? OFFSET ?';

        return static::runPaged($sql, ['open', $tenantId], $limit, $offset)->fetchAll();
    }
}
