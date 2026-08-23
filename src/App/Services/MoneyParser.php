<?php

namespace Keel\App\Services;

/**
 * Permissive money parsing for spreadsheet exports, always to integer cents.
 *
 * No float is created at any point: the integer and fractional halves are read
 * as separate integers and combined, so 0.1 + 0.2 problems cannot arise.
 *
 * Separator handling, which is where these parsers usually go wrong:
 *
 *   "3,200.00"  both present  -> the rightmost is the decimal point   -> 320000
 *   "1.234,56"  both present  -> the rightmost is the decimal point   -> 123456
 *   "3,200"     one, 3 digits -> thousands separator                  -> 320000
 *   "1.234"     one, 3 digits -> thousands separator                  -> 123400
 *   "1,50"      one, 2 digits -> decimal separator                    ->    150
 *   "0.07"      one, 2 digits -> decimal separator                    ->      7
 *
 * A lone separator followed by exactly three digits is read as a thousands
 * separator because money is not written to three decimal places. That makes
 * "1.234" mean one thousand two hundred thirty-four in both conventions, which
 * is what a spreadsheet user means either way.
 */
class MoneyParser
{
    /**
     * Currency codes and symbols worth recognising on the way in.
     */
    private const SYMBOLS = [
        '$' => 'USD',
        '£' => 'GBP',
        '€' => 'EUR',
        '¥' => 'JPY',
        '₹' => 'INR',
        'A$' => 'AUD',
        'C$' => 'CAD',
        'NZ$' => 'NZD',
    ];

    /**
     * Parse into cents plus any currency the value declared.
     *
     * @return array{cents:int, currency:?string}|null null when unreadable
     */
    public static function parse(string $value): ?array
    {
        $original = $value;
        $value = self::tidy($value);

        if ($value === '') {
            return null;
        }

        $currency = self::extractCurrency($value);
        $negative = false;

        // Accounting style: (1,200.00) means minus 1,200.00.
        if (preg_match('/^\((.*)\)$/', $value, $matches) === 1) {
            $negative = true;
            $value = $matches[1];
        }

        // Strip currency symbols, ISO codes, and stray spaces.
        $value = preg_replace('/[^\d.,\-+]/u', '', $value) ?? '';

        if (str_starts_with($value, '-')) {
            $negative = true;
        }

        $value = ltrim($value, '+-');

        if ($value === '' || preg_match('/\d/', $value) !== 1) {
            return null;
        }

        $cents = self::toCents($value);

        if ($cents === null) {
            return null;
        }

        return [
            'cents' => $negative ? -$cents : $cents,
            'currency' => $currency,
        ];
    }

    /**
     * Cents only, for callers that already know the currency.
     */
    public static function parseToCents(string $value): ?int
    {
        return self::parse($value)['cents'] ?? null;
    }

    /**
     * Render integer cents for display. Kept here so parsing and formatting
     * cannot drift apart.
     */
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $symbol = array_search(strtoupper($currency), self::SYMBOLS, true);
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        $formatted = number_format(intdiv($absolute, 100), 0, '.', ',')
            . '.'
            . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $sign . (is_string($symbol) ? $symbol : strtoupper($currency) . ' ') . $formatted;
    }

    // ------------------------------------------------------------- internals

    /**
     * Convert a sign-free, symbol-free numeric string to cents.
     */
    private static function toCents(string $value): ?int
    {
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both present: whichever comes last is the decimal point.
            $decimalSeparator = $lastDot > $lastComma ? '.' : ',';
            $thousandsSeparator = $decimalSeparator === '.' ? ',' : '.';

            $value = str_replace($thousandsSeparator, '', $value);
            [$whole, $fraction] = self::split($value, $decimalSeparator);
        } elseif ($lastDot !== false || $lastComma !== false) {
            $separator = $lastDot !== false ? '.' : ',';
            $parts = explode($separator, $value);
            $tail = (string) end($parts);

            // Repeated separators can only be thousands grouping (1.234.567).
            // A single one followed by exactly three digits is grouping too.
            if (count($parts) > 2 || strlen($tail) === 3) {
                $whole = str_replace($separator, '', $value);
                $fraction = '';
            } else {
                [$whole, $fraction] = self::split($value, $separator);
            }
        } else {
            $whole = $value;
            $fraction = '';
        }

        if ($whole === '') {
            $whole = '0';
        }

        if (preg_match('/^\d+$/', $whole) !== 1) {
            return null;
        }

        // More than two decimal places is not a money amount we can store
        // without silently rounding someone's invoice.
        if ($fraction !== '' && preg_match('/^\d{1,2}$/', $fraction) !== 1) {
            return null;
        }

        $fraction = str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        return (int) $whole * 100 + (int) $fraction;
    }

    /**
     * @return array{0:string, 1:string}
     */
    private static function split(string $value, string $separator): array
    {
        $position = strrpos($value, $separator);

        if ($position === false) {
            return [$value, ''];
        }

        return [substr($value, 0, $position), substr($value, $position + 1)];
    }

    private static function extractCurrency(string $value): ?string
    {
        $upper = strtoupper($value);

        // Prefer an explicit ISO code, then fall back to a symbol.
        if (preg_match('/\b(USD|EUR|GBP|CAD|AUD|NZD|JPY|CHF|SEK|NOK|DKK|INR|ZAR|SGD|HKD|MXN|BRL|PLN)\b/', $upper, $matches) === 1) {
            return $matches[1];
        }

        // Longest symbols first so A$ beats $.
        $symbols = self::SYMBOLS;
        uksort($symbols, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($symbols as $symbol => $code) {
            if (str_contains($value, $symbol)) {
                return $code;
            }
        }

        return null;
    }

    private static function tidy(string $value): string
    {
        // Non-breaking and thin spaces are common thousands separators in exports.
        $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xEF\xBB\xBF"], ' ', $value);
        $value = trim($value);

        // A space used purely as a thousands separator: "1 200,50".
        return preg_replace('/(?<=\d) (?=\d{3}\b)/', '', $value) ?? $value;
    }
}
