<?php

namespace Keel\App\Jobs;

use DateTimeImmutable;
use Keel\App\Services\Clock;
use Keel\App\Services\Dates;
use Keel\App\Services\FoundingCounter;
use Keel\App\Services\Timezones;
use Keel\Core\Activity;
use Keel\Core\Database;
use Keel\Core\Env;
use Keel\Core\Mailer;
use Throwable;

/**
 * Putting an unused founding slot back in the pool.
 *
 * The slot is claimed at signup, which is what the homepage promises. The price
 * of that promise is that fifty people who sign up and never come back would
 * consume the entire cohort, so the hold expires: thirty days to start a paid
 * subscription, and the place goes back if they do not.
 *
 * Two things this must get right.
 *
 * **Warn before, not after.** Losing a discount you were promised is annoying;
 * finding out afterwards, with no chance to keep it, is a reason to stop
 * trusting the sender. The warning goes out a week ahead and once only --
 * `lapse_warning_sent_at` is what stops it becoming a daily nag, which is not a
 * better warning but a worse one.
 *
 * **A paying workspace is never released.** The check is for a live
 * subscription, not for a date, so somebody who subscribed on day two keeps
 * their slot forever and the stale `reserved_until` on their row simply stops
 * mattering.
 */
class ReleaseExpiredFoundingSlotsJob
{
    /** How long before the lapse the warning goes out. */
    private const WARN_DAYS_BEFORE = 7;

    /** Subscription states that count as "they are paying". */
    private const PAYING = ['active', 'past_due', 'trialing'];

    /**
     * @return array{warned:int, released:int, errors:string[]}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $result = ['warned' => 0, 'released' => 0, 'errors' => []];

        foreach ($this->approachingLapse($now) as $slot) {
            try {
                if ($this->warn($slot, $now)) {
                    $result['warned']++;
                }
            } catch (Throwable $exception) {
                $result['errors'][] = 'Slot ' . $slot['slot_number'] . ': ' . $exception->getMessage();
            }
        }

        foreach ($this->lapsed($now) as $slot) {
            try {
                if ($this->release($slot, $now)) {
                    $result['released']++;
                }
            } catch (Throwable $exception) {
                $result['errors'][] = 'Slot ' . $slot['slot_number'] . ': ' . $exception->getMessage();
            }
        }

        return $result;
    }

    // -------------------------------------------------------------- the work

    /**
     * Hand the slot back.
     *
     * Conditional on the row still looking the way it did when it was selected.
     * Between the query and this write the workspace may have subscribed, and a
     * blind UPDATE would take a founding place off somebody who had just paid
     * for it.
     */
    private function release(array $slot, DateTimeImmutable $now): bool
    {
        $tenantId = (int) $slot['tenant_id'];

        $released = Database::transaction(function () use ($slot, $tenantId, $now): bool {
            $update = Database::connection()->prepare(
                'UPDATE founding_slots
                 SET tenant_id = NULL, claimed_at = NULL, reserved_until = NULL, lapse_warning_sent_at = NULL
                 WHERE slot_number = ? AND tenant_id = ? AND reserved_until IS NOT NULL AND reserved_until <= ?'
            );
            $update->execute([$slot['slot_number'], $tenantId, Clock::toDatabase($now)]);

            if ($update->rowCount() === 0) {
                return false;
            }

            Database::connection()
                ->prepare('UPDATE organizations SET is_founding = 0, founding_slot = NULL WHERE id = ?')
                ->execute([$tenantId]);

            return true;
        });

        if (!$released) {
            return false;
        }

        // A place has come back. The counter should say so.
        FoundingCounter::forget();

        // Every release is logged, and logged against the workspace it was
        // taken from -- a slot reappearing on the counter with no record of
        // where it came from is not something anybody can audit later.
        Activity::log('founding.slot_released', 'Organization', $tenantId, [
            'slot' => (int) $slot['slot_number'],
            'reserved_until' => (string) $slot['reserved_until'],
            'reason' => 'no paid subscription within the hold period',
        ], $tenantId);

        error_log(sprintf(
            '[Duely] Founding slot %d released from workspace %d (held until %s, never subscribed)',
            (int) $slot['slot_number'],
            $tenantId,
            (string) $slot['reserved_until']
        ));

        $this->emailOwner(
            $tenantId,
            'Your founding place has been released',
            '<p>The founding rate was held for you for 30 days and has now been released, so somebody '
            . 'else can take it.</p>'
            . '<p>Nothing about your account has changed. Duely still works exactly as it did, your '
            . 'reminders are untouched, and the free plan has no time limit &mdash; only the founding '
            . 'price is gone.</p>'
        );

        return true;
    }

    /**
     * Tell them it is coming, once.
     */
    private function warn(array $slot, DateTimeImmutable $now): bool
    {
        $tenantId = (int) $slot['tenant_id'];
        $lapsesAt = Clock::fromDatabase((string) $slot['reserved_until']);

        // Marked before sending. A mail failure that left this unset would warn
        // them again tomorrow, and the day after.
        $marked = Database::connection()->prepare(
            'UPDATE founding_slots SET lapse_warning_sent_at = ?
             WHERE slot_number = ? AND tenant_id = ? AND lapse_warning_sent_at IS NULL'
        );
        $marked->execute([Clock::toDatabase($now), $slot['slot_number'], $tenantId]);

        if ($marked->rowCount() === 0) {
            return false;
        }

        $timezone = Timezones::forWorkspace($tenantId);
        $when = Dates::long($lapsesAt?->setTimezone(Timezones::zone($timezone)));

        Activity::log('founding.lapse_warned', 'Organization', $tenantId, [
            'slot' => (int) $slot['slot_number'],
            'lapses_at' => (string) $slot['reserved_until'],
        ], $tenantId);

        $this->emailOwner(
            $tenantId,
            'Your founding place is held until ' . $when,
            '<p>You have one of the 50 founding places, which holds the $19 rate for as long as you '
            . 'stay subscribed.</p>'
            . '<p>It is held until <strong>' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</strong>. '
            . 'If you have not started a paid plan by then it goes back into the pool for somebody '
            . 'else, and the standard price applies afterwards.</p>'
            . '<p>Nothing else changes either way &mdash; the free plan has no time limit.</p>'
        );

        return true;
    }

    // -------------------------------------------------------------- queries

    /**
     * Held, unpaid, and lapsing within the warning window.
     */
    private function approachingLapse(DateTimeImmutable $now): array
    {
        return $this->rows(
            'AND s.lapse_warning_sent_at IS NULL
             AND s.reserved_until > ?
             AND s.reserved_until <= ?',
            [
                Clock::toDatabase($now),
                Clock::toDatabase($now->modify('+' . self::WARN_DAYS_BEFORE . ' days')),
            ]
        );
    }

    /**
     * Held, unpaid, and out of time.
     */
    private function lapsed(DateTimeImmutable $now): array
    {
        return $this->rows('AND s.reserved_until <= ?', [Clock::toDatabase($now)]);
    }

    /**
     * @param array<int, string> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $extraWhere, array $bindings): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PAYING), '?'));

        $sql = 'SELECT s.slot_number, s.tenant_id, s.reserved_until
                FROM founding_slots s
                WHERE s.tenant_id IS NOT NULL
                  AND s.reserved_until IS NOT NULL
                  -- A live subscription keeps the slot indefinitely, so the
                  -- date on the row stops meaning anything once they pay.
                  AND NOT EXISTS (
                      SELECT 1 FROM subscriptions sub
                      WHERE sub.tenant_id = s.tenant_id
                        AND sub.status IN (' . $placeholders . ')
                  )
                  ' . $extraWhere . '
                ORDER BY s.slot_number ASC';

        $statement = Database::connection()->prepare($sql);
        $statement->execute(array_merge(self::PAYING, $bindings));

        return $statement->fetchAll() ?: [];
    }

    private function emailOwner(int $tenantId, string $subject, string $body): void
    {
        $statement = Database::connection()->prepare(
            'SELECT name, email FROM users WHERE organization_id = ? ORDER BY (role = ?) DESC, id ASC LIMIT 1'
        );
        $statement->execute([$tenantId, 'owner']);
        $owner = $statement->fetch();

        if (!$owner) {
            return;
        }

        $url = rtrim((string) Env::get('APP_URL', ''), '/') . '/billing/upgrade';

        Mailer::send(
            (string) $owner['email'],
            (string) ($owner['name'] ?? $owner['email']),
            $subject,
            '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;'
            . 'max-width:480px;margin:0 auto;padding:32px;color:#171717;font-size:15px;line-height:1.6;">'
            . $body
            . '<p style="margin-top:24px;"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;'
            . 'padding:12px 24px;border-radius:8px;font-weight:600;">See the plans</a></p>'
            . '</div>'
        );
    }
}
