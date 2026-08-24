<?php

namespace Keel\App\Services;

/**
 * The escalation ramp: teal, then amber, then red.
 *
 * These are the three dots in the Duely mark, and they mean the same thing
 * everywhere they appear — a days-overdue counter, a dot on the progress rail,
 * and the tone label under it are the same rung of the same ladder, so they
 * must be the same colour. Before this existed they were three unrelated
 * Tailwind literals that happened to agree, and only by accident.
 *
 * Every method returns a complete literal class string rather than building one
 * by concatenation. Tailwind's scanner reads source files as text: a class
 * assembled as 'text-tone-' . $tone is invisible to it and gets purged, so the
 * strings here are written out in full on purpose.
 */
class ToneRamp
{
    public const POLITE = 'polite';
    public const FIRM = 'firm';
    public const FINAL = 'final';

    /** A rung with no escalation of its own. Carries no ramp colour. */
    public const NEUTRAL = 'neutral';

    /**
     * The tone a given lateness belongs to.
     *
     * The thresholds are the seeded ladder — a nudge at three days, a firmer
     * note at fourteen, a last one at thirty. A workspace that edits its own
     * sequence gets its own rungs on the rail; this is for the places that
     * only know a number of days, like a table cell.
     */
    public static function forDaysOverdue(int $days): string
    {
        return match (true) {
            $days >= 30 => self::FINAL,
            $days >= 14 => self::FIRM,
            default => self::POLITE,
        };
    }

    /**
     * Normalise a stored tone onto the ramp.
     *
     * The column also allows 'friendly' and 'neutral', which predate the
     * polite/firm/final vocabulary. Friendly is the same rung as polite;
     * neutral deliberately has no ramp colour of its own.
     */
    public static function normalise(?string $tone): string
    {
        return match (strtolower(trim((string) $tone))) {
            'polite', 'friendly' => self::POLITE,
            'firm' => self::FIRM,
            'final' => self::FINAL,
            default => self::NEUTRAL,
        };
    }

    /**
     * Ramp colour as text.
     */
    public static function text(string $tone): string
    {
        return match (self::normalise($tone)) {
            self::POLITE => 'text-tone-polite',
            self::FIRM => 'text-tone-firm',
            self::FINAL => 'text-tone-final',
            default => 'text-text-muted',
        };
    }

    /**
     * A filled dot: the rung has happened.
     */
    public static function dotFilled(string $tone): string
    {
        return match (self::normalise($tone)) {
            self::POLITE => 'bg-tone-polite border-tone-polite',
            self::FIRM => 'bg-tone-firm border-tone-firm',
            self::FINAL => 'bg-tone-final border-tone-final',
            default => 'bg-surface-muted border-card-border',
        };
    }

    /**
     * An outlined dot: the rung is due but has not fired.
     */
    public static function dotDue(string $tone): string
    {
        return match (self::normalise($tone)) {
            self::POLITE => 'bg-tone-polite-soft border-tone-polite',
            self::FIRM => 'bg-tone-firm-soft border-tone-firm',
            self::FINAL => 'bg-tone-final-soft border-tone-final',
            default => 'bg-surface-muted border-card-border',
        };
    }

    /**
     * The connecting line between rungs.
     */
    public static function line(string $tone): string
    {
        return match (self::normalise($tone)) {
            self::POLITE => 'bg-tone-polite',
            self::FIRM => 'bg-tone-firm',
            self::FINAL => 'bg-tone-final',
            default => 'bg-card-border',
        };
    }

    /**
     * Border, tint and text together, for a status pill.
     */
    public static function pill(string $tone): string
    {
        return match (self::normalise($tone)) {
            self::POLITE => 'border-tone-polite-border bg-tone-polite-soft text-tone-polite',
            self::FIRM => 'border-tone-firm-border bg-tone-firm-soft text-tone-firm',
            self::FINAL => 'border-tone-final-border bg-tone-final-soft text-tone-final',
            default => 'border-card-border bg-surface-muted text-text-muted',
        };
    }

    /**
     * The tint alone, for a row that needs attention without shouting.
     */
    public static function soft(string $tone): string
    {
        return match (self::normalise($tone)) {
            self::POLITE => 'bg-tone-polite-soft',
            self::FIRM => 'bg-tone-firm-soft',
            self::FINAL => 'bg-tone-final-soft',
            default => '',
        };
    }
}
