<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Chase;
use Keel\App\Models\Invoice;
use Keel\Core\Activity;
use Keel\Core\Database;

/**
 * Marking an invoice paid, and stopping its chase.
 *
 * This lived inline in ChaseController::markPaid. It moved here when the Stripe
 * webhook needed to do the same thing: two copies of "mark paid, pause the
 * chase, log it" is two things to keep in step, and the one that drifts is the
 * one nobody is looking at. There is one path now, and the only difference
 * between a human clicking the button and Stripe reporting a payment is the
 * `source` string and whether an undo token is minted.
 */
class InvoicePaymentMarker
{
    public const SOURCE_MANUAL = 'manual';

    /**
     * `stripe`, not `webhook`: `paid_source` is an ENUM that already had a
     * value for this, and the timeline already renders it as "Payment received
     * through Stripe". The column says where the money came from, not which
     * code path noticed.
     */
    public const SOURCE_WEBHOOK = 'stripe';

    /**
     * The state to restore if this is undone. Captured before anything changes,
     * so undo replays what was actually there rather than guessing.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(array $invoice, ?array $chase): array
    {
        return [
            'invoice' => [
                'status' => $invoice['status'],
                'paid_at' => $invoice['paid_at'],
                'paid_source' => $invoice['paid_source'] ?? null,
            ],
            'chase' => $chase === null ? null : [
                'id' => (int) $chase['id'],
                'status' => $chase['status'],
                'paused_reason' => $chase['paused_reason'],
                'paused_at' => $chase['paused_at'],
                'next_send_at' => $chase['next_send_at'],
                'current_position' => (int) $chase['current_position'],
            ],
        ];
    }

    /**
     * Mark it paid and stop the chase.
     *
     * Both writes happen in one transaction: an invoice recorded as paid while
     * its chase keeps running is the failure that emails a client a demand for
     * money they have already sent.
     *
     * @return array{paid:bool, chase_paused:bool, chase:?array, snapshot:array}
     */
    public function markPaid(
        int $tenantId,
        array $invoice,
        string $source,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= Clock::now();
        $invoiceId = (int) $invoice['id'];
        $chase = Chase::forInvoice($tenantId, $invoiceId);
        $snapshot = self::snapshot($invoice, $chase);

        $wasRunning = $chase !== null
            && in_array($chase['status'], [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE], true);

        Database::transaction(function () use ($tenantId, $invoiceId, $source, $now, $chase, $wasRunning): void {
            Invoice::update($tenantId, $invoiceId, [
                'status' => Invoice::STATUS_PAID,
                'paid_at' => Clock::toDatabase($now),
                'paid_source' => $source,
            ]);

            if ($wasRunning) {
                Chase::pause($tenantId, (int) $chase['id'], Chase::PAUSE_INVOICE_PAID);
            }
        });

        Activity::log('invoice.marked_paid', 'Invoice', $invoiceId, [
            'source' => $source,
            'chase_paused' => $wasRunning,
        ]);

        return [
            'paid' => true,
            'chase_paused' => $wasRunning,
            'chase' => $chase,
            'snapshot' => $snapshot,
        ];
    }
}
