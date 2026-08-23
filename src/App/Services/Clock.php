<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The single place datetimes cross the database boundary.
 *
 * Duely stores DATETIME columns as UTC wall-clock with no offset, because
 * that is all MySQL's DATETIME can hold. PHP's `new DateTimeImmutable($s)`
 * interprets such a string in the process's *local* timezone, which on a
 * typical server is not UTC. Reading a stored value back without saying so
 * therefore shifts it by the server's offset — silently, and only on machines
 * where the two zones differ, which is the worst way for a bug to behave.
 *
 * Every write goes through `toDatabase()` and every read through
 * `fromDatabase()`, so the round trip is symmetric by construction.
 */
class Clock
{
    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    /**
     * Parse a DATETIME read out of the database.
     */
    public static function fromDatabase(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return new DateTimeImmutable($value, self::utc());
    }

    /**
     * Render a moment for storage, normalising to UTC first.
     */
    public static function toDatabase(?DateTimeImmutable $moment): ?string
    {
        return $moment?->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }
}
