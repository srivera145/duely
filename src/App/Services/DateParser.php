<?php

namespace Keel\App\Services;

use DateTimeImmutable;

/**
 * Permissive date parsing for spreadsheet exports.
 *
 * The target user keeps invoices in a spreadsheet, so due dates arrive in
 * whatever their locale and their tool happened to produce. This accepts the
 * shapes that actually turn up and refuses to guess when guessing would be
 * wrong: 03/04/2026 is genuinely ambiguous, so the caller supplies a locale.
 */
class DateParser
{
    public const LOCALE_AUTO = 'auto';
    public const LOCALE_MDY = 'mdy';
    public const LOCALE_DMY = 'dmy';

    /**
     * Two-digit years below this pivot are read as 20xx, at or above as 19xx.
     * An invoice dated 1970 is far less likely than one dated 2026.
     */
    private const CENTURY_PIVOT = 70;

    /**
     * Excel and Google Sheets count days from this epoch, so a column that lost
     * its formatting arrives as a bare integer like 46000.
     */
    private const SPREADSHEET_EPOCH = '1899-12-30';

    private const MONTH_NAMES = [
        'jan' => 1, 'january' => 1,
        'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    /**
     * Parse a date, or return null when it cannot be read.
     *
     * @param string $locale one of the LOCALE_* constants, used only to break
     *                       genuine day/month ambiguity
     */
    public static function parse(string $value, string $locale = self::LOCALE_AUTO): ?DateTimeImmutable
    {
        $value = self::tidy($value);

        if ($value === '') {
            return null;
        }

        foreach ([
            self::parseIso($value),
            self::parseTextualMonth($value),
            self::parseNumeric($value, $locale),
            self::parseSpreadsheetSerial($value),
        ] as $parsed) {
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Parse to the Y-m-d string the invoices table stores.
     */
    public static function parseToDateString(string $value, string $locale = self::LOCALE_AUTO): ?string
    {
        return self::parse($value, $locale)?->format('Y-m-d');
    }

    /**
     * Would this value mean different things under MDY and DMY?
     *
     * The importer uses this to warn that a locale choice actually changed the
     * outcome, rather than silently committing one reading of 03/04/2026.
     */
    public static function isAmbiguous(string $value): bool
    {
        $value = self::tidy($value);

        if (preg_match('#^(\d{1,2})[/\-. ](\d{1,2})[/\-. ](\d{2,4})$#', $value, $matches) !== 1) {
            return false;
        }

        $first = (int) $matches[1];
        $second = (int) $matches[2];

        // Both could be a month, and they are not the same number.
        return $first >= 1 && $first <= 12
            && $second >= 1 && $second <= 12
            && $first !== $second;
    }

    /**
     * Guess the locale a column of dates was written in, by looking for values
     * that can only be read one way (a 13+ in the first or second position).
     *
     * @param string[] $samples
     * @return string one of the LOCALE_* constants; AUTO when nothing decides it
     */
    public static function detectLocale(array $samples): string
    {
        $dayFirst = 0;
        $monthFirst = 0;

        foreach ($samples as $sample) {
            $sample = self::tidy((string) $sample);

            if (preg_match('#^(\d{1,2})[/\-. ](\d{1,2})[/\-. ](\d{2,4})$#', $sample, $matches) !== 1) {
                continue;
            }

            $first = (int) $matches[1];
            $second = (int) $matches[2];

            if ($first > 12 && $second <= 12) {
                $dayFirst++;
            } elseif ($second > 12 && $first <= 12) {
                $monthFirst++;
            }
        }

        if ($dayFirst > $monthFirst) {
            return self::LOCALE_DMY;
        }

        if ($monthFirst > $dayFirst) {
            return self::LOCALE_MDY;
        }

        return self::LOCALE_AUTO;
    }

    // ------------------------------------------------------------- internals

    /**
     * YYYY-MM-DD and friends. Unambiguous, so it wins before anything else.
     */
    private static function parseIso(string $value): ?DateTimeImmutable
    {
        if (preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', $value, $matches) !== 1) {
            return null;
        }

        return self::build((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    /**
     * "5 Jan 2026", "Jan 5, 2026", "January 5 2026", "5-Jan-26".
     */
    private static function parseTextualMonth(string $value): ?DateTimeImmutable
    {
        $names = implode('|', array_keys(self::MONTH_NAMES));

        // Month first: Jan 5, 2026
        if (preg_match('#^(' . $names . ')[\s,\-./]+(\d{1,2})(?:st|nd|rd|th)?[\s,\-./]+(\d{2,4})$#i', $value, $matches) === 1) {
            return self::build(
                self::normaliseYear((int) $matches[3]),
                self::MONTH_NAMES[strtolower($matches[1])],
                (int) $matches[2]
            );
        }

        // Day first: 5 Jan 2026
        if (preg_match('#^(\d{1,2})(?:st|nd|rd|th)?[\s,\-./]+(' . $names . ')[\s,\-./]+(\d{2,4})$#i', $value, $matches) === 1) {
            return self::build(
                self::normaliseYear((int) $matches[3]),
                self::MONTH_NAMES[strtolower($matches[2])],
                (int) $matches[1]
            );
        }

        return null;
    }

    /**
     * All-numeric with separators. Where the value decides its own reading we
     * follow it; where it does not, the locale breaks the tie.
     */
    private static function parseNumeric(string $value, string $locale): ?DateTimeImmutable
    {
        if (preg_match('#^(\d{1,2})[/\-. ](\d{1,2})[/\-. ](\d{2,4})$#', $value, $matches) !== 1) {
            return null;
        }

        $first = (int) $matches[1];
        $second = (int) $matches[2];
        $year = self::normaliseYear((int) $matches[3]);

        // A value over 12 cannot be a month, so it settles the order itself.
        if ($first > 12 && $second <= 12) {
            return self::build($year, $second, $first);
        }

        if ($second > 12 && $first <= 12) {
            return self::build($year, $first, $second);
        }

        // Genuinely ambiguous: defer to the locale, defaulting to MDY.
        return $locale === self::LOCALE_DMY
            ? self::build($year, $second, $first)
            : self::build($year, $first, $second);
    }

    /**
     * A bare integer from a spreadsheet column that lost its date formatting.
     */
    private static function parseSpreadsheetSerial(string $value): ?DateTimeImmutable
    {
        if (preg_match('#^\d{4,6}$#', $value) !== 1) {
            return null;
        }

        $serial = (int) $value;

        // Bracket the range to plausible invoice dates (roughly 1990–2130) so a
        // stray invoice number is never mistaken for a date.
        if ($serial < 32874 || $serial > 84000) {
            return null;
        }

        return (new DateTimeImmutable(self::SPREADSHEET_EPOCH))
            ->modify('+' . $serial . ' days')
            ->setTime(0, 0, 0);
    }

    private static function build(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return (new DateTimeImmutable())
            ->setDate($year, $month, $day)
            ->setTime(0, 0, 0);
    }

    private static function normaliseYear(int $year): int
    {
        if ($year >= 100) {
            return $year;
        }

        return $year < self::CENTURY_PIVOT ? 2000 + $year : 1900 + $year;
    }

    private static function tidy(string $value): string
    {
        // Strip the non-breaking spaces spreadsheet exports love.
        $value = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
