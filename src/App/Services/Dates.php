<?php

namespace Keel\App\Services;

use DateTimeImmutable;

/**
 * How Duely writes a date.
 *
 * US convention throughout — **August 12, 2026** and **08/12/2026** — because
 * that is what the people using this read. It was a mixture before: reminder
 * emails said "12 August 2026", the marketing pages said the same, and the
 * tables printed the raw `2026-08-12` straight out of the database.
 *
 * One place, so the next screen inherits it instead of picking its own. There
 * are three shapes and each has a job:
 *
 *   `long()`   prose — an email a client reads, a page heading
 *   `short()`  tables, where the column has to stay narrow
 *   `withTime()` a moment rather than a day
 *
 * Parsing is unaffected and already correct: DateParser resolves an ambiguous
 * `08/12/2026` as month-first, so an imported spreadsheet and a rendered date
 * agree about which number is the month.
 */
class Dates
{
    /** "August 12, 2026" */
    public const LONG = 'F j, Y';

    /** "08/12/2026" */
    public const SHORT = 'm/d/Y';

    /** "Aug 12, 2026" — long enough to be unambiguous, short enough for a cell. */
    public const MEDIUM = 'M j, Y';

    /** "August 12, 2026 at 3:04 PM" */
    public const LONG_WITH_TIME = 'F j, Y \a\t g:i A';

    /** "Aug 12, 3:04 PM" — the same day, when the year is obvious from context. */
    public const MEDIUM_WITH_TIME = 'M j, g:i A';

    /**
     * A date for prose. Accepts either a stored `Y-m-d` string or a moment.
     */
    public static function long(DateTimeImmutable|string|null $value): string
    {
        return self::format($value, self::LONG);
    }

    public static function medium(DateTimeImmutable|string|null $value): string
    {
        return self::format($value, self::MEDIUM);
    }

    /**
     * A date for a table cell.
     */
    public static function short(DateTimeImmutable|string|null $value): string
    {
        return self::format($value, self::SHORT);
    }

    /**
     * A stored UTC moment, rendered as a date and time in a display timezone.
     *
     * The zone is required rather than defaulted, because a timestamp shown in
     * the wrong zone is worse than one shown in the wrong format: the format is
     * merely unfamiliar, the zone is wrong.
     */
    public static function withTime(
        DateTimeImmutable|string|null $value,
        string $timezone,
        string $format = self::LONG_WITH_TIME
    ): string {
        $moment = self::toMoment($value);

        if ($moment === null) {
            return '';
        }

        return $moment->setTimezone(Timezones::zone($timezone))->format($format);
    }

    /**
     * Same, in the compact form a table wants.
     */
    public static function shortWithTime(DateTimeImmutable|string|null $value, string $timezone): string
    {
        return self::withTime($value, $timezone, 'm/d/Y g:i A');
    }

    // -------------------------------------------------------------- internals

    private static function format(DateTimeImmutable|string|null $value, string $format): string
    {
        return self::toMoment($value)?->format($format) ?? '';
    }

    /**
     * A `Y-m-d` date column, a `Y-m-d H:i:s` datetime column, or a moment.
     *
     * A bare date is read as UTC midnight and never shifted — a due date is a
     * day, not an instant, and rendering it in a timezone would move it across
     * midnight and show the wrong day.
     */
    private static function toMoment(DateTimeImmutable|string|null $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return Clock::fromDatabase(
            // A date-only column needs a time before Clock will parse it.
            strlen($value) === 10 ? $value . ' 00:00:00' : $value
        );
    }
}
