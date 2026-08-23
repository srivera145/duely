<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Models\Chase;
use Keel\App\Models\Client;
use Keel\App\Models\Invoice;
use Keel\App\Models\Sequence;
use Keel\App\Models\SequenceStep;

/**
 * Decides when each reminder should go out.
 *
 * Two rules do the real work here.
 *
 * The first is that offsets are measured from the invoice due date, never from
 * "now". That is what lets an invoice imported already 18 days overdue slot
 * into the middle of the ladder instead of restarting at day 3.
 *
 * The second is the catch-up rule: entering the ladder late fires the single
 * most advanced applicable step, not every step that has been missed. A client
 * who has been overdue for three months should get one firm note, not three
 * emails in ten seconds.
 *
 * Times are computed in the client's timezone and stored in UTC, so a 9am
 * window means 9am where the person reading it lives.
 */
class ChaseScheduler
{
    /**
     * Begin chasing an invoice.
     *
     * @return array{chase_id:int, created:bool, entry_position:int, next_send_at:?DateTimeImmutable, reason:?string}
     */
    public function start(
        int $tenantId,
        int $invoiceId,
        ?int $sequenceId = null,
        ?int $emailAccountId = null,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= Clock::now();

        $invoice = Invoice::withClient($tenantId, $invoiceId);

        if ($invoice === null) {
            return $this->refusal('That invoice does not exist.');
        }

        if ($invoice['status'] !== Invoice::STATUS_OPEN) {
            return $this->refusal('Only open invoices can be chased.');
        }

        $client = Client::find($tenantId, (int) $invoice['client_id']);

        if ($client !== null && $client['suppressed_at'] !== null) {
            return $this->refusal('This client has been suppressed and will not be emailed.');
        }

        $sequence = $sequenceId !== null
            ? Sequence::find($tenantId, $sequenceId)
            : Sequence::defaultSequence($tenantId);

        if ($sequence === null) {
            return $this->refusal('No reminder sequence is set up yet.');
        }

        $emailAccountId ??= $this->defaultAccountId($tenantId);

        $plan = $this->planEntry($tenantId, $invoice, $sequence, $now);

        if ($plan['step'] === null && $plan['next_send_at'] === null) {
            return $this->refusal('This sequence has no reminders left to send for that due date.');
        }

        $existing = Chase::forInvoice($tenantId, $invoiceId);

        if ($existing !== null) {
            return [
                'chase_id' => (int) $existing['id'],
                'created' => false,
                'entry_position' => (int) $existing['current_position'],
                'next_send_at' => Clock::fromDatabase($existing['next_send_at']),
                'reason' => 'This invoice is already being chased.',
            ];
        }

        $chaseId = Chase::start(
            $tenantId,
            $invoiceId,
            (int) $sequence['id'],
            $emailAccountId,
            $plan['next_send_at'],
            // The chase sits just *before* the step it will fire, so the sender
            // can look up "the step after current_position" uniformly.
            $plan['step'] === null ? 0 : (int) $plan['step']['position'] - 1
        );

        return [
            'chase_id' => $chaseId,
            'created' => true,
            'entry_position' => $plan['step'] === null ? 0 : (int) $plan['step']['position'],
            'next_send_at' => $plan['next_send_at'],
            'reason' => null,
        ];
    }

    /**
     * Which step an invoice enters at, and when that step should fire.
     *
     * @return array{step:?array, next_send_at:?DateTimeImmutable, caught_up:bool}
     */
    public function planEntry(int $tenantId, array $invoice, array $sequence, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $sequenceId = (int) $sequence['id'];
        $daysOverdue = Invoice::daysOverdue($invoice, $now);

        // The single most advanced step whose offset the due date has passed.
        // Deliberately not "every step we missed".
        $entry = SequenceStep::entryStep($tenantId, $sequenceId, $daysOverdue);

        if ($entry !== null) {
            return [
                'step' => $entry,
                // Already due, so it goes out at the next moment inside the
                // sequence's send window rather than immediately.
                'next_send_at' => $this->nextWindowSlot($now, $sequence, $invoice, $now),
                'caught_up' => true,
            ];
        }

        // Nothing due yet: schedule the first step that is still ahead.
        $pending = SequenceStep::nextPendingStep($tenantId, $sequenceId, $daysOverdue);

        if ($pending === null) {
            return ['step' => null, 'next_send_at' => null, 'caught_up' => false];
        }

        return [
            'step' => $pending,
            'next_send_at' => $this->sendAtFor($pending, $invoice, $sequence, $now),
            'caught_up' => false,
        ];
    }

    /**
     * After a step has been sent, when does the following one go?
     *
     * @return array{step:?array, next_send_at:?DateTimeImmutable}
     */
    public function planNext(int $tenantId, array $chase, array $invoice, array $sequence, int $justSentPosition, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $next = SequenceStep::nextAfter($tenantId, (int) $sequence['id'], $justSentPosition);

        if ($next === null) {
            return ['step' => null, 'next_send_at' => null];
        }

        $sendAt = $this->sendAtFor($next, $invoice, $sequence, $now);

        // A step whose offset has already passed still waits for the next
        // window slot rather than firing back-to-back with the one just sent.
        if ($sendAt < $now) {
            $sendAt = $this->nextWindowSlot($now, $sequence, $invoice, $now);
        }

        return ['step' => $next, 'next_send_at' => $sendAt];
    }

    /**
     * The absolute UTC moment a step should fire: due date + offset, moved into
     * the send window, in the client's timezone.
     */
    public function sendAtFor(array $step, array $invoice, array $sequence, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $timezone = $this->timezoneFor($invoice);

        $due = new DateTimeImmutable((string) $invoice['due_date'] . ' 00:00:00', $timezone);
        $offset = (int) $step['offset_days'];

        $target = $offset >= 0
            ? $due->modify('+' . $offset . ' days')
            : $due->modify('-' . abs($offset) . ' days');

        // Start of the window on the target day, then shift forward as needed.
        $target = $this->atWindowStart($target, $sequence);

        return $this->shiftIntoWindow($target, $sequence, $timezone);
    }

    /**
     * The soonest legal moment from `$from` onwards.
     */
    public function nextWindowSlot(DateTimeImmutable $from, array $sequence, array $invoice, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $timezone = $this->timezoneFor($invoice);
        $local = $from->setTimezone($timezone);

        return $this->shiftIntoWindow($local, $sequence, $timezone);
    }

    /**
     * Move a local time forward until it sits inside the send window on a day
     * the sequence is willing to send.
     */
    public function shiftIntoWindow(DateTimeImmutable $local, array $sequence, DateTimeZone $timezone): DateTimeImmutable
    {
        $local = $local->setTimezone($timezone);

        [$startHour, $startMinute] = $this->timeParts((string) ($sequence['send_window_start'] ?? '09:00:00'));
        [$endHour, $endMinute] = $this->timeParts((string) ($sequence['send_window_end'] ?? '16:00:00'));

        $skipWeekends = (int) ($sequence['skip_weekends'] ?? 1) === 1;

        // Bounded loop: a week is more than enough to find a sending day, and
        // it guarantees termination even if a sequence were configured with an
        // impossible window.
        for ($attempt = 0; $attempt < 14; $attempt++) {
            $windowStart = $local->setTime($startHour, $startMinute, 0);
            $windowEnd = $local->setTime($endHour, $endMinute, 0);

            if ($skipWeekends && $this->isWeekend($local)) {
                $local = $local->modify('+1 day')->setTime($startHour, $startMinute, 0);
                continue;
            }

            if ($local < $windowStart) {
                $local = $windowStart;
            }

            if ($local > $windowEnd) {
                // Too late today; try tomorrow morning.
                $local = $local->modify('+1 day')->setTime($startHour, $startMinute, 0);
                continue;
            }

            return $local->setTimezone(new DateTimeZone('UTC'));
        }

        // Unreachable with any valid window, but a sane fallback beats a loop.
        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Is this moment inside the sequence's send window right now?
     *
     * Checked immediately before sending, because a message queued yesterday
     * could otherwise go out at 04:00 after a worker outage.
     */
    public function isWithinWindow(DateTimeImmutable $moment, array $sequence, array $invoice): bool
    {
        $timezone = $this->timezoneFor($invoice);
        $local = $moment->setTimezone($timezone);

        if ((int) ($sequence['skip_weekends'] ?? 1) === 1 && $this->isWeekend($local)) {
            return false;
        }

        [$startHour, $startMinute] = $this->timeParts((string) ($sequence['send_window_start'] ?? '09:00:00'));
        [$endHour, $endMinute] = $this->timeParts((string) ($sequence['send_window_end'] ?? '16:00:00'));

        return $local >= $local->setTime($startHour, $startMinute, 0)
            && $local <= $local->setTime($endHour, $endMinute, 59);
    }

    /**
     * The timezone reminders should be timed in: the client's, because 9am
     * should mean 9am where the person reading it lives.
     */
    public function timezoneFor(array $invoice): DateTimeZone
    {
        $name = trim((string) ($invoice['client_timezone'] ?? ''));

        if ($name === '') {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            // A junk timezone on a client row must not stop their reminders.
            return new DateTimeZone('UTC');
        }
    }

    // ------------------------------------------------------------- internals

    private function atWindowStart(DateTimeImmutable $day, array $sequence): DateTimeImmutable
    {
        [$hour, $minute] = $this->timeParts((string) ($sequence['send_window_start'] ?? '09:00:00'));

        return $day->setTime($hour, $minute, 0);
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function timeParts(string $time): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return [9, 0];
        }

        return [min(23, (int) $matches[1]), min(59, (int) $matches[2])];
    }

    private function isWeekend(DateTimeImmutable $local): bool
    {
        // 6 = Saturday, 7 = Sunday.
        return (int) $local->format('N') >= 6;
    }

    private function defaultAccountId(int $tenantId): ?int
    {
        $account = \Keel\App\Models\EmailAccount::sendingAccount($tenantId);

        return $account === null ? null : (int) $account['id'];
    }

    /**
     * @return array{chase_id:int, created:bool, entry_position:int, next_send_at:null, reason:string}
     */
    private function refusal(string $reason): array
    {
        return [
            'chase_id' => 0,
            'created' => false,
            'entry_position' => 0,
            'next_send_at' => null,
            'reason' => $reason,
        ];
    }
}
