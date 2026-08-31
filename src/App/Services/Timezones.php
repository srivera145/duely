<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Keel\Core\Database;

/**
 * Timezones: which are real, which one this workspace uses, and how to render a
 * stored UTC moment in one.
 *
 * Two distinct zones, and conflating them is the bug this exists to prevent:
 *
 *   The **workspace** zone is presentation. Every timestamp a user reads is
 *   rendered in it. It changes nothing about what is stored or when anything is
 *   sent — switching it re-labels the past, it does not move it.
 *
 *   The **client** zone is delivery. ChaseScheduler times the 09:00–16:00 window
 *   against it, because nine in the morning should mean nine in the morning
 *   where the person reading the email lives.
 *
 * Storage stays UTC throughout. Nothing here writes a datetime.
 */
class Timezones
{
    public const DEFAULT = 'UTC';

    /** Offered first in the picker, because most users are in one of them. */
    private const COMMON = [
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix',
        'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu',
        'America/Toronto', 'America/Vancouver', 'America/Mexico_City',
        'Europe/London', 'Europe/Dublin', 'Europe/Paris', 'Europe/Berlin',
        'Europe/Madrid', 'Europe/Rome', 'Europe/Amsterdam', 'Europe/Lisbon',
        'Europe/Warsaw', 'Europe/Athens',
        'Australia/Sydney', 'Australia/Melbourne', 'Australia/Perth',
        'Pacific/Auckland', 'Asia/Tokyo', 'Asia/Singapore', 'Asia/Hong_Kong',
        'Asia/Kolkata', 'Asia/Dubai', 'Africa/Johannesburg', 'America/Sao_Paulo',
        'UTC',
    ];

    /**
     * Is this a real IANA identifier?
     *
     * Checked against the list rather than by constructing a DateTimeZone,
     * because the constructor also accepts offsets like "+05:00" and
     * abbreviations like "EST" — neither of which survives a DST transition,
     * which is exactly what a chase scheduled across one depends on.
     */
    public static function isValid(?string $name): bool
    {
        $name = trim((string) $name);

        if ($name === '') {
            return false;
        }

        return in_array($name, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * A valid identifier, or the fallback. Used where a bad value must not be
     * fatal — never as a substitute for validating input.
     */
    public static function normalise(?string $name, string $fallback = self::DEFAULT): string
    {
        return self::isValid($name) ? trim((string) $name) : $fallback;
    }

    public static function zone(?string $name, string $fallback = self::DEFAULT): DateTimeZone
    {
        return new DateTimeZone(self::normalise($name, $fallback));
    }

    /**
     * This workspace's display zone.
     */
    public static function forWorkspace(int $tenantId): string
    {
        $statement = Database::connection()->prepare(
            'SELECT timezone FROM organizations WHERE id = ? LIMIT 1'
        );
        $statement->execute([$tenantId]);

        return self::normalise($statement->fetchColumn() ?: null);
    }

    public static function setForWorkspace(int $tenantId, string $name): bool
    {
        if (!self::isValid($name)) {
            return false;
        }

        $statement = Database::connection()->prepare(
            'UPDATE organizations SET timezone = ? WHERE id = ?'
        );
        $statement->execute([trim($name), $tenantId]);

        return true;
    }

    /**
     * Render a stored UTC moment in a zone.
     *
     * The moment itself is untouched — this is a change of label, not of
     * instant, which is the whole distinction between display and delivery.
     */
    public static function render(
        ?DateTimeImmutable $moment,
        string $timezone,
        string $format = Dates::MEDIUM_WITH_TIME
    ): ?string {
        return $moment?->setTimezone(self::zone($timezone))->format($format);
    }

    /**
     * Read a stored string and render it in one step, for views.
     */
    public static function renderStored(
        ?string $stored,
        string $timezone,
        string $format = Dates::MEDIUM_WITH_TIME
    ): ?string {
        return self::render(Clock::fromDatabase($stored), $timezone, $format);
    }

    /**
     * "America/Denver — MDT, 09:14" — the name, its current abbreviation, and
     * the time there now.
     *
     * The current time is the part that makes a picker usable: a user choosing a
     * client's zone can see at a glance whether they have picked the one where
     * it is currently mid-afternoon.
     */
    public static function label(string $name, ?DateTimeImmutable $now = null): string
    {
        if (!self::isValid($name)) {
            return $name;
        }

        $now ??= Clock::now();
        $local = $now->setTimezone(new DateTimeZone($name));

        return $name . ' — ' . $local->format('T, g:i A');
    }

    /**
     * The picker's contents: common zones first, then everything else.
     *
     * Grouped rather than flat because the full IANA list is over four hundred
     * entries and a user in Denver should not have to scroll past Africa/Abidjan
     * to find it.
     *
     * @return array<string, array<int, array{value:string, label:string}>>
     */
    public static function catalogue(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $common = [];

        foreach (self::COMMON as $name) {
            $common[] = ['value' => $name, 'label' => self::label($name, $now)];
        }

        $rest = [];

        foreach (DateTimeZone::listIdentifiers() as $name) {
            if (in_array($name, self::COMMON, true)) {
                continue;
            }

            $rest[] = ['value' => $name, 'label' => $name];
        }

        return ['Common' => $common, 'All timezones' => $rest];
    }

    /**
     * The current time in a zone, for the live hint beside a picker.
     */
    public static function currentTimeIn(string $name, ?DateTimeImmutable $now = null): ?string
    {
        if (!self::isValid($name)) {
            return null;
        }

        return ($now ?? Clock::now())->setTimezone(new DateTimeZone($name))->format('g:i A');
    }
}
