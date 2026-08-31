<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\Invoice;
use Keel\App\Models\InvoicePayment;
use Keel\App\Models\ReplyEvent;
use Keel\App\Models\SequenceStep;

/**
 * Everything that has happened to one invoice, in order.
 *
 * The point of this screen is that a user can answer "what did we actually
 * send, and what came back?" without leaving Duely and digging through their
 * sent folder. So the sent messages carry their full rendered body — that text
 * is already stored, and hiding it would send the user to their mail client.
 *
 * Replies carry only the snippet the poller kept, because Duely never stored
 * the rest.
 */
class InvoiceTimeline
{
    /**
     * @return array{
     *     invoice:array, chase:?array, sequence_steps:array,
     *     rail:array, events:array, next_send_at:?string
     * }|null
     */
    public function build(int $tenantId, int $invoiceId, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= Clock::now();

        $invoice = Invoice::withClient($tenantId, $invoiceId);

        if ($invoice === null) {
            return null;
        }

        $chase = Chase::forInvoice($tenantId, $invoiceId);
        $steps = $chase === null ? [] : SequenceStep::forSequence($tenantId, (int) $chase['sequence_id']);
        $messages = $chase === null ? [] : ChaseMessage::forChase($tenantId, (int) $chase['id']);
        $replies = $chase === null ? [] : ReplyEvent::forChase($tenantId, (int) $chase['id']);
        $payments = InvoicePayment::forInvoice($tenantId, $invoiceId);

        return [
            'invoice' => $invoice,
            'chase' => $chase,
            'sequence_steps' => $steps,
            'rail' => $this->rail($invoice, $steps, $messages, $now),
            'events' => $this->events($invoice, $chase, $messages, $replies, $payments),
            'next_send_at' => $chase['next_send_at'] ?? null,
        ];
    }

    /**
     * The progress rail: one rung per step in the sequence, each showing
     * whether it has been sent, is due, or is still ahead.
     *
     * Built from the sequence's actual offsets rather than hardcoded to
     * 3/14/30, so a tenant who edits their ladder sees their own ladder.
     *
     * @return array<int, array{position:int, offset_days:int, label:string, tone:string, state:string, at:?string}>
     */
    public function rail(array $invoice, array $steps, array $messages, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $daysOverdue = Invoice::daysOverdue($invoice, $now);

        $sentByPosition = [];
        foreach ($messages as $message) {
            if ($message['status'] === ChaseMessage::STATUS_SENT) {
                $sentByPosition[(int) $message['position']] = $message['sent_at'];
            }
        }

        $isSettled = $invoice['status'] !== Invoice::STATUS_OPEN;
        $rail = [];

        foreach ($steps as $step) {
            $position = (int) $step['position'];
            $offset = (int) $step['offset_days'];
            $sentAt = $sentByPosition[$position] ?? null;

            $state = match (true) {
                $sentAt !== null => 'sent',
                $isSettled => 'cancelled',
                $daysOverdue >= $offset => 'due',
                default => 'upcoming',
            };

            $rail[] = [
                'position' => $position,
                'offset_days' => $offset,
                'label' => $this->railLabel($offset),
                'tone' => (string) $step['tone'],
                'state' => $state,
                'at' => $sentAt,
            ];
        }

        return $rail;
    }

    /**
     * The chronological feed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function events(
        array $invoice,
        ?array $chase,
        array $messages,
        array $replies,
        array $payments = []
    ): array {
        $events = [];

        $events[] = [
            'type' => 'created',
            'at' => (string) $invoice['created_at'],
            'title' => 'Invoice ' . $invoice['number'] . ' added',
            'detail' => MoneyParser::format((int) $invoice['amount_cents'], (string) $invoice['currency'])
                . ' due ' . Dates::long($invoice['due_date']),
        ];

        if ($chase !== null && !empty($chase['started_at'])) {
            $events[] = [
                'type' => 'chase_started',
                'at' => (string) $chase['started_at'],
                'title' => 'Reminders switched on',
                'detail' => null,
            ];
        }

        foreach ($messages as $message) {
            $events[] = [
                'type' => 'message',
                'at' => (string) ($message['sent_at'] ?? $message['scheduled_for'] ?? $message['created_at']),
                'title' => $this->messageTitle($message),
                'detail' => $message['subject'],
                'status' => $message['status'],
                'position' => (int) $message['position'],
                'failed_reason' => $message['failed_reason'],
                // The stored body, so the user can read exactly what went out.
                'body_text' => $message['body_text'],
                'body_html' => $message['body_html'],
                'to_email' => $message['to_email'],
            ];
        }

        foreach ($replies as $reply) {
            $events[] = [
                'type' => 'reply',
                'at' => (string) $reply['received_at'],
                'title' => $this->replyTitle($reply),
                'detail' => $reply['subject'],
                'reply_type' => $reply['type'],
                // Only ever the snippet; the message body was never stored.
                'snippet' => $reply['snippet'],
                'from_email' => $reply['from_email'],
                'action_taken' => $reply['action_taken'],
            ];
        }

        foreach ($payments as $payment) {
            $events[] = [
                'type' => 'payment',
                'at' => (string) $payment['created_at'],
                'title' => $this->paymentTitle((string) $payment['outcome']),
                'detail' => MoneyParser::format(
                    (int) $payment['amount_cents'],
                    (string) $payment['currency']
                ) . ' received through Stripe',
                'outcome' => (string) $payment['outcome'],
                // What is still owed, so a part payment reads as a number and
                // not as a puzzle the user has to do arithmetic on.
                'outstanding' => (string) $payment['outcome'] === InvoicePayment::OUTCOME_PARTIAL
                    ? MoneyParser::format(
                        (int) $invoice['amount_cents'] - (int) $payment['amount_cents'],
                        (string) $invoice['currency']
                    )
                    : null,
            ];
        }

        if ($invoice['status'] === Invoice::STATUS_PAID && !empty($invoice['paid_at'])) {
            $events[] = [
                'type' => 'paid',
                'at' => (string) $invoice['paid_at'],
                'title' => 'Marked paid',
                'detail' => $this->paidDetail($invoice),
            ];
        }

        if ($invoice['status'] === Invoice::STATUS_VOID) {
            $events[] = [
                'type' => 'void',
                'at' => (string) $invoice['updated_at'],
                'title' => 'Invoice voided',
                'detail' => null,
            ];
        }

        if ($chase !== null && $chase['status'] === Chase::STATUS_PAUSED && !empty($chase['paused_at'])) {
            $events[] = [
                'type' => 'paused',
                'at' => (string) $chase['paused_at'],
                'title' => 'Reminders paused',
                'detail' => $this->pauseReason((string) $chase['paused_reason']),
            ];
        }

        // Oldest first: this reads as a story, not a log.
        usort($events, static function (array $a, array $b): int {
            return [$a['at'], $a['type']] <=> [$b['at'], $b['type']];
        });

        return $events;
    }

    private function paymentTitle(string $outcome): string
    {
        return match ($outcome) {
            InvoicePayment::OUTCOME_PARTIAL => 'Part payment received',
            InvoicePayment::OUTCOME_OVERPAID => 'Overpayment received',
            default => 'Payment received',
        };
    }

    private function messageTitle(array $message): string
    {
        $position = (int) $message['position'];

        return match ($message['status']) {
            ChaseMessage::STATUS_SENT => 'Reminder ' . $position . ' sent',
            ChaseMessage::STATUS_FAILED => 'Reminder ' . $position . ' could not be sent',
            ChaseMessage::STATUS_BOUNCED => 'Reminder ' . $position . ' bounced',
            default => 'Reminder ' . $position . ' queued',
        };
    }

    private function replyTitle(array $reply): string
    {
        return match ($reply['type']) {
            ReplyEvent::TYPE_REPLY => 'Client replied',
            ReplyEvent::TYPE_AUTO_REPLY => 'Automatic reply received',
            ReplyEvent::TYPE_BOUNCE => (int) $reply['is_hard_bounce'] === 1
                ? 'Email bounced permanently'
                : 'Delivery delayed',
            ReplyEvent::TYPE_COMPLAINT => 'Marked as spam',
            default => 'Message received',
        };
    }

    private function paidDetail(array $invoice): string
    {
        return match ($invoice['paid_source'] ?? null) {
            'manual' => 'Marked paid by you',
            'stripe' => 'Payment received through Stripe',
            'import' => 'Marked paid during an import',
            'reply' => 'Marked paid after a client reply',
            default => 'Payment recorded',
        };
    }

    private function pauseReason(string $reason): string
    {
        return match ($reason) {
            Chase::PAUSE_CLIENT_REPLIED => 'The client replied, so Duely stopped chasing.',
            Chase::PAUSE_INVOICE_PAID => 'The invoice was marked paid.',
            Chase::PAUSE_BOUNCED => 'The last reminder bounced.',
            Chase::PAUSE_NEEDS_REAUTH => 'The mailbox needs reconnecting.',
            default => 'Paused manually.',
        };
    }

    private function railLabel(int $offset): string
    {
        if ($offset === 0) {
            return 'Due date';
        }

        return $offset < 0
            ? abs($offset) . ' days before'
            : 'Day ' . $offset;
    }
}
