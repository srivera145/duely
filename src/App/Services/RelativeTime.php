<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a timestamp into the phrase someone actually wants to read.
 *
 * "in 2 days" answers the question the dashboard is asking. A raw timestamp
 * makes the reader do the arithmetic, and makes them do it in whatever
 * timezone the server happens to be in.
 */
class RelativeTime
{
    /**
     * A short relative phrase: "in 2 days", "3 hours ago", "just now".
     */
    public static function phrase(?DateTimeImmutable $moment, ?DateTimeImmutable $now = null): ?string
    {
        if ($moment === null) {
            return null;
        }

        $now ??= Clock::now();
        $seconds = $moment->getTimestamp() - $now->getTimestamp();
        $future = $seconds >= 0;
        $seconds = abs($seconds);

        if ($seconds < 60) {
            return $future ? 'in a moment' : 'just now';
        }

        $unit = match (true) {
            $seconds < 3600 => ['minute', (int) round($seconds / 60)],
            $seconds < 86400 => ['hour', (int) round($seconds / 3600)],
            $seconds < 2592000 => ['day', (int) round($seconds / 86400)],
            $seconds < 31536000 => ['month', (int) round($seconds / 2592000)],
            default => ['year', (int) round($seconds / 31536000)],
        };

        [$name, $count] = $unit;
        $label = $count . ' ' . $name . ($count === 1 ? '' : 's');

        return $future ? 'in ' . $label : $label . ' ago';
    }

    /**
     * The same moment rendered in a specific timezone, for the tooltip behind
     * the relative phrase.
     */
    public static function inTimezone(?DateTimeImmutable $moment, string $timezone = 'UTC'): ?string
    {
        if ($moment === null) {
            return null;
        }

        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            $zone = Clock::utc();
        }

        return $moment->setTimezone($zone)->format('D j M, H:i T');
    }

    /**
     * How overdue something is, in words.
     */
    public static function overdueLabel(int $days): string
    {
        return match (true) {
            $days > 1 => $days . ' days overdue',
            $days === 1 => '1 day overdue',
            $days === 0 => 'Due today',
            $days === -1 => 'Due tomorrow',
            default => 'Due in ' . abs($days) . ' days',
        };
    }
}
