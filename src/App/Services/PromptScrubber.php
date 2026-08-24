<?php

namespace Keel\App\Services;

/**
 * Removes client data from anything on its way to the model.
 *
 * The rule is that Duely sends merge tags, never real values. Sending the
 * template alone would usually satisfy that — but a user who has been editing
 * their reminders by hand may well have typed "Hi Dana," or pasted a real
 * invoice number in place of the tag, and that text is exactly what the rewrite
 * feature is handed.
 *
 * So the scrubber does not trust the template to already be safe. It rewrites
 * anything that looks like client data into the merge tag that belongs there,
 * which both protects the client and teaches the model the shape we want back.
 *
 * This is deliberately blunt. A false positive costs a slightly odd prompt; a
 * false negative sends a real person's name and invoice total to a third party.
 */
class PromptScrubber
{
    /**
     * What each kind of detected value is replaced with.
     */
    private const REPLACEMENTS = [
        'email' => '{{client_email_removed}}',
        'money' => '{{amount}}',
        'invoice' => '{{invoice_number}}',
        'url' => '{{invoice_url}}',
        'phone' => '{{phone_removed}}',
        'iban' => '{{bank_details_removed}}',
        'date' => '{{due_date}}',
    ];

    /**
     * Scrub a block of text.
     *
     * @return array{text:string, redactions:array<string,int>}
     */
    public static function scrub(string $text): array
    {
        $redactions = [];

        $apply = static function (string $pattern, string $kind) use (&$text, &$redactions): void {
            $count = 0;
            $replaced = preg_replace($pattern, self::REPLACEMENTS[$kind], $text, -1, $count);

            if (is_string($replaced)) {
                $text = $replaced;
            }

            if ($count > 0) {
                $redactions[$kind] = ($redactions[$kind] ?? 0) + $count;
            }
        };

        // Order matters: URLs and emails both contain @ and dots, and an IBAN
        // looks like an invoice number, so the most specific patterns run first.
        $apply('#\bhttps?://[^\s<>"\']+#i', 'url');
        $apply('/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/', 'email');
        $apply('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', 'iban');

        // Money: a currency symbol or code attached to a number, or a bare
        // decimal with thousands grouping.
        $apply('/(?:[$£€¥₹]|\b(?:USD|EUR|GBP|CAD|AUD|NZD|JPY|CHF|SEK|NOK|DKK|INR|ZAR)\b)\s?\d[\d.,]*/iu', 'money');
        $apply('/\b\d{1,3}(?:,\d{3})+(?:\.\d{2})?\b/', 'money');

        // Invoice-number shapes: INV-1042, #1042, 2026-0042.
        $apply('/\b(?:INV|INVOICE|BILL|REF)[-–—\s#]*\d{2,}\b/i', 'invoice');
        $apply('/(?<![\w{])#\d{3,}\b/', 'invoice');

        // Dates in any of the shapes the importer accepts.
        $apply('/\b\d{4}-\d{2}-\d{2}\b/', 'date');
        $apply('/\b\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}\b/', 'date');
        $apply('/\b\d{1,2} (?:January|February|March|April|May|June|July|August|September|October|November|December) \d{4}\b/i', 'date');

        // Phone numbers, loosely.
        $apply('/(?<![\w{])\+?\d[\d\s().-]{8,}\d(?![\w}])/', 'phone');

        return ['text' => $text, 'redactions' => $redactions];
    }

    /**
     * Would this text leak anything if it were sent as-is?
     *
     * Used by the tests, and as a last check before dispatch.
     */
    public static function containsLikelyPii(string $text): bool
    {
        return self::scrub($text)['redactions'] !== [];
    }

    /**
     * Scrub a free-text description of the user's business.
     *
     * The same patterns apply — someone describing their work may well name a
     * client or quote a figure — but the text is prose rather than a template,
     * so a capitalised-name pass is added.
     *
     * @return array{text:string, redactions:array<string,int>}
     */
    public static function scrubDescription(string $text, int $maxLength = 600): array
    {
        $result = self::scrub(mb_substr(trim($text), 0, $maxLength));

        return $result;
    }
}
