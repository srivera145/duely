<?php

namespace Keel\App\Models;

use InvalidArgumentException;
use Keel\Core\Database;
use PDO;
use PDOStatement;

/**
 * Tenant-scoped base model.
 *
 * Keel ships no base model, so Duely defines one. The contract it enforces:
 *
 *   1. Every read and write is scoped by `tenant_id`, which is a required first
 *      argument on every public method. There is no code path that reaches a
 *      tenant table without a tenant id in the WHERE clause.
 *   2. All values travel as bound parameters. SQL text is assembled only from
 *      literal fragments and identifiers checked against a per-model allowlist,
 *      so no caller-supplied string is ever interpolated into a statement.
 *   3. `create()` and `update()` ignore any `tenant_id` or `id` a caller passes
 *      in the attribute array, so a hostile payload cannot reassign ownership.
 */
abstract class BaseModel
{
    /**
     * The table this model owns. Must be a hardcoded literal in the subclass.
     */
    abstract protected static function table(): string;

    /**
     * Columns a caller may write via create()/update(), and filter on via the
     * by-column finders. Never include `id` or `tenant_id`; those are managed here.
     *
     * @return string[]
     */
    abstract protected static function columns(): array;

    // ---------------------------------------------------------------- reads

    public static function find(int $tenantId, int $id): ?array
    {
        $sql = 'SELECT * FROM ' . static::quotedTable() . ' WHERE tenant_id = ? AND id = ? LIMIT 1';

        return static::run($sql, [$tenantId, $id])->fetch() ?: null;
    }

    /**
     * @param int[] $ids
     */
    public static function findMany(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $sql = 'SELECT * FROM ' . static::quotedTable()
            . ' WHERE tenant_id = ? AND id IN (' . static::placeholders(count($ids)) . ')'
            . ' ORDER BY id ASC';

        return static::run($sql, array_merge([$tenantId], $ids))->fetchAll();
    }

    public static function all(int $tenantId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM ' . static::quotedTable()
            . ' WHERE tenant_id = ? ORDER BY id DESC LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId], $limit, $offset)->fetchAll();
    }

    public static function count(int $tenantId): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . static::quotedTable() . ' WHERE tenant_id = ?';

        return (int) static::run($sql, [$tenantId])->fetchColumn();
    }

    public static function exists(int $tenantId, int $id): bool
    {
        $sql = 'SELECT 1 FROM ' . static::quotedTable() . ' WHERE tenant_id = ? AND id = ? LIMIT 1';

        return (bool) static::run($sql, [$tenantId, $id])->fetchColumn();
    }

    // --------------------------------------------------------------- writes

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(int $tenantId, array $attributes): int
    {
        $attributes = static::filterAttributes($attributes);

        if ($attributes === []) {
            throw new InvalidArgumentException('No writable attributes supplied to create().');
        }

        $columns = array_keys($attributes);
        $columnList = implode(', ', array_map(
            static fn (string $column): string => static::quoteIdentifier($column),
            $columns
        ));
        $valueList = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));

        $sql = 'INSERT INTO ' . static::quotedTable()
            . ' (tenant_id, ' . $columnList . ') VALUES (:tenant_id, ' . $valueList . ')';

        $attributes['tenant_id'] = $tenantId;
        static::run($sql, $attributes);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function update(int $tenantId, int $id, array $attributes): bool
    {
        $attributes = static::filterAttributes($attributes);

        if ($attributes === []) {
            return false;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => static::quoteIdentifier($column) . ' = :' . $column,
            array_keys($attributes)
        ));

        $sql = 'UPDATE ' . static::quotedTable() . ' SET ' . $assignments
            . ' WHERE tenant_id = :tenant_id AND id = :id';

        $attributes['tenant_id'] = $tenantId;
        $attributes['id'] = $id;

        return static::run($sql, $attributes)->rowCount() > 0;
    }

    public static function delete(int $tenantId, int $id): bool
    {
        $sql = 'DELETE FROM ' . static::quotedTable() . ' WHERE tenant_id = ? AND id = ?';

        return static::run($sql, [$tenantId, $id])->rowCount() > 0;
    }

    // ------------------------------------------------- protected query kit

    /**
     * Fetch one row matching an allowlisted column, always within the tenant.
     */
    protected static function findOneBy(int $tenantId, string $column, mixed $value): ?array
    {
        $sql = 'SELECT * FROM ' . static::quotedTable()
            . ' WHERE tenant_id = ? AND ' . static::assertColumn($column) . ' = ? LIMIT 1';

        return static::run($sql, [$tenantId, $value])->fetch() ?: null;
    }

    /**
     * Fetch all rows matching an allowlisted column, always within the tenant.
     */
    protected static function findAllBy(
        int $tenantId,
        string $column,
        mixed $value,
        int $limit = 100,
        int $offset = 0
    ): array {
        $sql = 'SELECT * FROM ' . static::quotedTable()
            . ' WHERE tenant_id = ? AND ' . static::assertColumn($column) . ' = ?'
            . ' ORDER BY id DESC LIMIT ? OFFSET ?';

        return static::runPaged($sql, [$tenantId, $value], $limit, $offset)->fetchAll();
    }

    /**
     * Prepare and execute with positional or named bindings.
     *
     * @param array<int|string, mixed> $bindings
     */
    protected static function run(string $sql, array $bindings = []): PDOStatement
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /**
     * Same as run(), plus integer-bound LIMIT/OFFSET. MySQL rejects those as
     * strings when emulated prepares are off, so they need explicit PARAM_INT.
     *
     * @param list<mixed> $bindings positional bindings that precede LIMIT/OFFSET
     */
    protected static function runPaged(string $sql, array $bindings, int $limit, int $offset): PDOStatement
    {
        $statement = Database::connection()->prepare($sql);

        $position = 1;
        foreach ($bindings as $value) {
            $statement->bindValue($position++, $value);
        }

        $statement->bindValue($position++, max(1, min($limit, 1000)), PDO::PARAM_INT);
        $statement->bindValue($position, max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement;
    }

    /**
     * Drop anything not on the write allowlist. `id` and `tenant_id` are never
     * writable through here, so a caller cannot move a row to another tenant.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected static function filterAttributes(array $attributes): array
    {
        $writable = array_diff(static::columns(), ['id', 'tenant_id']);

        return array_intersect_key($attributes, array_flip($writable));
    }

    protected static function assertColumn(string $column): string
    {
        if (!in_array($column, static::columns(), true)) {
            throw new InvalidArgumentException('Unknown column for ' . static::table() . ': ' . $column);
        }

        return static::quoteIdentifier($column);
    }

    protected static function quotedTable(): string
    {
        return static::quoteIdentifier(static::table());
    }

    protected static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Illegal SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }

    protected static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
