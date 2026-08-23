<?php

/**
 * Duely's default escalation ladder — the product's opinion about how to ask
 * for money without damaging the relationship.
 *
 * This file is the copy. Editing it changes what every new tenant is seeded
 * with; it does not touch tenants that already exist, because by then the
 * sequence is theirs to edit.
 *
 * How the copy is written, and why:
 *
 *   - It reads as one freelancer writing to one client, not as a system.
 *     Short sentences, contractions, no "please be advised".
 *   - Every step gives the client an easy way out that is not payment:
 *     reply and tell me what's holding it up. Most late invoices are stuck on
 *     a PO number or an approval, not on unwillingness to pay.
 *   - No capitals, no bold threats, no "FINAL NOTICE". The last step is firm
 *     and factual — it states where things stand and what happens next — but
 *     it does not accuse anyone or threaten consequences.
 *   - Offsets count from the invoice due date, so an invoice imported already
 *     overdue enters at the right rung rather than starting again at day 3.
 *
 * @return array{
 *     name:string, description:string, tone:string,
 *     send_window_start:string, send_window_end:string, skip_weekends:int,
 *     steps:array<int, array{position:int, offset_days:int, tone:string,
 *                            is_final:int, subject_template:string, body_template:string}>
 * }
 */

return [
    'name' => 'Default reminders',
    'description' => 'Three reminders: a nudge at three days, a firmer note at two weeks, and a final message at a month.',
    'tone' => 'polite',

    // Office hours in the sender's timezone, weekends left alone. A reminder
    // that lands at 3am reads as a robot, which defeats the point.
    'send_window_start' => '09:00:00',
    'send_window_end' => '16:00:00',
    'skip_weekends' => 1,

    'steps' => [
        [
            'position' => 1,
            'offset_days' => 3,
            'tone' => 'polite',
            'is_final' => 0,
            'subject_template' => 'Quick nudge on invoice {{invoice_number}}',
            'body_template' => <<<'BODY'
Hi {{client_first_name}},

Hope you're well. Just a quick note that invoice {{invoice_number}} for {{amount}} was due on {{due_date}}.

No drama at all — these things slip through, and it may already be in your payment run. If so, ignore me entirely.

{{invoice_url}}

If anything about the invoice needs changing, just reply and I'll sort it out.

Thanks,
{{sender_name}}
BODY,
        ],

        [
            'position' => 2,
            'offset_days' => 14,
            'tone' => 'firm',
            'is_final' => 0,
            'subject_template' => 'Invoice {{invoice_number}} is {{days_overdue}} days overdue',
            'body_template' => <<<'BODY'
Hi {{client_first_name}},

Following up on invoice {{invoice_number}} for {{amount}}, which was due on {{due_date}} and is now {{days_overdue}} days overdue.

{{invoice_url}}

I'd rather not keep sending these, so if something's holding it up — an approval, a PO number, a missing detail on my end — tell me and I'll work around it.

Otherwise, could you let me know when I can expect payment?

Thanks,
{{sender_name}}
BODY,
        ],

        [
            'position' => 3,
            'offset_days' => 30,
            'tone' => 'final',
            'is_final' => 1,
            'subject_template' => 'Invoice {{invoice_number}} — {{days_overdue}} days overdue',
            'body_template' => <<<'BODY'
Hi {{client_first_name}},

Invoice {{invoice_number}} for {{amount}} is now {{days_overdue}} days past its due date of {{due_date}}, and I haven't heard back on my earlier notes.

{{invoice_url}}

This is the last reminder I'll send automatically. I'd much rather resolve this with you directly than take it any further, so if there's a problem — with the work, the invoice, or the timing — I'd genuinely like to know.

Could you reply and let me know where this stands?

Thanks,
{{sender_name}}
BODY,
        ],
    ],
];
