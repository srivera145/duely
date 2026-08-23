<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\Invoice;

/**
 * Renders a reminder template into the two parts every message is sent with:
 * an HTML body and a plain-text body.
 *
 * Escaping discipline, which is the whole point of this class:
 *
 *   The template is authored as plain text. For the HTML part, both the
 *   template's own literal text and each merge value must be escaped exactly
 *   once. The naive approach — substitute first, then escape the result —
 *   double-escapes any value that itself contains a & or a quote, so a client
 *   called "Smith & Sons" arrives as "Smith &amp;amp; Sons".
 *
 *   Instead the template is split into literal segments and tags, each piece is
 *   escaped once in isolation, and the pieces are joined. Nothing that has been
 *   escaped is ever escaped again.
 *
 * An unknown tag renders as an empty string and raises a warning. A literal
 * {{foo}} must never reach a client — it is the single most obvious way for an
 * automated reminder to announce that it is automated.
 */
class TemplateRenderer
{
    public const MODE_HTML = 'html';
    public const MODE_TEXT = 'text';

    /**
     * Every merge tag Duely supports, with the label and example the editor
     * shows beside it.
     *
     * @return array<string, array{label:string, example:string}>
     */
    public static function tags(): array
    {
        return [
            'client_name' => ['label' => 'Client name', 'example' => 'Dana Whitfield'],
            'client_first_name' => ['label' => 'Client first name', 'example' => 'Dana'],
            'company' => ['label' => 'Company', 'example' => 'Whitfield & Partners'],
            'invoice_number' => ['label' => 'Invoice number', 'example' => 'INV-1042'],
            'amount' => ['label' => 'Amount', 'example' => '$3,200.00'],
            'currency' => ['label' => 'Currency', 'example' => 'USD'],
            'due_date' => ['label' => 'Due date', 'example' => '5 August 2026'],
            'days_overdue' => ['label' => 'Days overdue', 'example' => '18'],
            'invoice_url' => ['label' => 'Payment link', 'example' => 'https://pay.example.com/inv-1042'],
            'sender_name' => ['label' => 'Your name', 'example' => 'Ada Lovelace'],
        ];
    }

    /**
     * @return string[]
     */
    public static function tagNames(): array
    {
        return array_keys(self::tags());
    }

    /**
     * Warnings raised by the most recent render, keyed by unknown tag name.
     *
     * @var array<string, int>
     */
    private array $warnings = [];

    /**
     * Render subject, text body and HTML body in one pass.
     *
     * @param array<string, string|int|null> $context
     * @return array{subject:string, text:string, html:string, warnings:string[]}
     */
    public function renderMessage(string $subjectTemplate, string $bodyTemplate, array $context): array
    {
        $this->warnings = [];

        // A subject line is never HTML.
        $subject = $this->substitute($subjectTemplate, $context, self::MODE_TEXT);
        $text = $this->substitute($bodyTemplate, $context, self::MODE_TEXT);
        $html = $this->substitute($bodyTemplate, $context, self::MODE_HTML);

        return [
            'subject' => $this->tidyText($subject, true),
            'text' => $this->tidyText($text),
            'html' => $this->toHtmlDocument($html),
            'warnings' => array_keys($this->warnings),
        ];
    }

    /**
     * Render one template. Kept public for the live preview panel.
     *
     * @param array<string, string|int|null> $context
     */
    public function render(string $template, array $context, string $mode = self::MODE_TEXT): string
    {
        $this->warnings = [];
        $rendered = $this->substitute($template, $context, $mode);

        return $mode === self::MODE_HTML
            ? $this->toHtmlDocument($rendered)
            : $this->tidyText($rendered);
    }

    /**
     * @return string[] unknown tags seen in the last render
     */
    public function warnings(): array
    {
        return array_keys($this->warnings);
    }

    /**
     * Tags used by a template that Duely does not recognise. Used by the editor
     * to warn while the user types, rather than at send time.
     *
     * @return string[]
     */
    public static function unknownTagsIn(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $template, $matches);

        $known = self::tagNames();
        $unknown = [];

        foreach ($matches[1] as $tag) {
            $tag = strtolower($tag);

            if (!in_array($tag, $known, true)) {
                $unknown[$tag] = true;
            }
        }

        return array_keys($unknown);
    }

    // ------------------------------------------------------------- contexts

    /**
     * Build merge values from real rows.
     *
     * @param array $invoice an invoice row joined to its client
     */
    public static function contextFor(array $invoice, string $senderName, ?DateTimeImmutable $asOf = null): array
    {
        $clientName = trim((string) ($invoice['client_name'] ?? ''));
        $amountCents = (int) ($invoice['amount_cents'] ?? 0);
        $currency = (string) ($invoice['currency'] ?? 'USD');

        $daysOverdue = isset($invoice['days_overdue'])
            ? (int) $invoice['days_overdue']
            : Invoice::daysOverdue($invoice, $asOf);

        return [
            'client_name' => $clientName,
            'client_first_name' => self::firstName($clientName),
            'company' => (string) ($invoice['client_company'] ?? ''),
            'invoice_number' => (string) ($invoice['number'] ?? ''),
            'amount' => MoneyParser::format($amountCents, $currency),
            'currency' => $currency,
            'due_date' => self::friendlyDate((string) ($invoice['due_date'] ?? '')),
            'days_overdue' => (string) max(0, $daysOverdue),
            'invoice_url' => (string) ($invoice['payment_url'] ?? ''),
            'sender_name' => $senderName,
        ];
    }

    /**
     * The invoice the live preview renders against.
     *
     * The company name deliberately contains an ampersand, so double-escaping
     * shows up immediately in the preview rather than in a client's inbox.
     */
    public static function sampleContext(string $senderName = 'Ada Lovelace'): array
    {
        return [
            'client_name' => 'Dana Whitfield',
            'client_first_name' => 'Dana',
            'company' => 'Whitfield & Partners',
            'invoice_number' => 'INV-1042',
            'amount' => '$3,200.00',
            'currency' => 'USD',
            'due_date' => '5 August 2026',
            'days_overdue' => '18',
            'invoice_url' => 'https://pay.example.com/inv-1042',
            'sender_name' => $senderName,
        ];
    }

    // ------------------------------------------------------------ internals

    /**
     * Split the template on merge tags and escape each piece exactly once.
     *
     * @param array<string, string|int|null> $context
     */
    private function substitute(string $template, array $context, string $mode): string
    {
        // PREG_SPLIT_DELIM_CAPTURE keeps the tag names in the result, so the
        // output alternates literal, tag, literal, tag, ...
        $pieces = preg_split(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            $template,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($pieces === false) {
            return '';
        }

        $known = self::tagNames();
        $output = '';

        foreach ($pieces as $index => $piece) {
            // Odd indexes are the captured tag names.
            if ($index % 2 === 0) {
                $output .= $mode === self::MODE_HTML ? $this->escapeHtml($piece) : $piece;
                continue;
            }

            $tag = strtolower($piece);

            if (!in_array($tag, $known, true)) {
                $this->noteUnknownTag($tag);
                continue;
            }

            $value = (string) ($context[$tag] ?? '');

            $output .= $mode === self::MODE_HTML
                ? $this->escapeHtml($value)
                // The plain-text part must never carry markup, whatever a
                // client name or a note happens to contain.
                : strip_tags($value);
        }

        return $output;
    }

    /**
     * An unknown tag is a template bug, not a client's problem: it renders as
     * nothing and is recorded so the editor and the logs both surface it.
     */
    private function noteUnknownTag(string $tag): void
    {
        $this->warnings[$tag] = ($this->warnings[$tag] ?? 0) + 1;

        // The tag name is authored by the tenant and contains no client data.
        error_log('[Duely] Unknown merge tag in reminder template: {{' . $tag . '}} rendered as empty.');
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Collapse the gaps an empty merge value leaves behind.
     *
     * A template with {{invoice_url}} on its own line looks wrong when the
     * invoice has no payment link, so runs of blank lines become one break.
     */
    private function tidyText(string $text, bool $singleLine = false): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Trailing spaces left by a removed tag mid-line.
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;

        if ($singleLine) {
            return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        }

        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Wrap the escaped body in a simple HTML email.
     *
     * The input is already escaped, so nothing here escapes again. Bare URLs
     * become links; the href is built from the already-escaped text, which is
     * safe for an attribute because escapeHtml() used ENT_QUOTES.
     */
    private function toHtmlDocument(string $escapedBody): string
    {
        $body = $this->tidyText($escapedBody);

        if ($body === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', $body) ?: [];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $paragraph = $this->linkify($paragraph);
            $paragraph = nl2br($paragraph, false);

            $html .= '<p style="margin:0 0 16px;">' . $paragraph . '</p>';
        }

        return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;'
            . 'font-size:15px;line-height:1.6;color:#0A0A0A;">' . $html . '</div>';
    }

    /**
     * Turn an already-escaped bare URL into a link.
     *
     * Only http and https are linked; anything else stays as text, so a
     * javascript: or data: URL pasted into a template cannot become clickable.
     */
    private function linkify(string $escaped): string
    {
        return preg_replace_callback(
            '#\bhttps?://[^\s<>"]+#i',
            static function (array $matches): string {
                $url = $matches[0];

                // Trailing punctuation belongs to the sentence, not the URL.
                $trailing = '';
                while ($url !== '' && str_contains('.,;:!?)', substr($url, -1))) {
                    $trailing = substr($url, -1) . $trailing;
                    $url = substr($url, 0, -1);
                }

                if ($url === '') {
                    return $matches[0];
                }

                return '<a href="' . $url . '" style="color:#22C55E;">' . $url . '</a>' . $trailing;
            },
            $escaped
        ) ?? $escaped;
    }

    private static function firstName(string $fullName): string
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return '';
        }

        // "Dana Whitfield" -> "Dana"; a single-word name stays whole.
        $parts = preg_split('/\s+/', $fullName) ?: [$fullName];

        return (string) $parts[0];
    }

    /**
     * "2026-08-05" reads better as "5 August 2026" in a sentence.
     */
    private static function friendlyDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $parsed = DateParser::parse($date);

        return $parsed === null ? $date : $parsed->format('j F Y');
    }
}
