<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\Core\Database;
use Keel\Core\Env;
use Throwable;

/**
 * Reads an invoice document into a draft.
 *
 * The output is always a *draft*. Nothing here writes an invoice, and nothing
 * here starts a chase. The user sees every extracted field in the ordinary
 * invoice form and confirms it, for the same reason the CSV import previews
 * before it commits: a wrong due date schedules a reminder on the wrong day,
 * and a wrong amount emails a client a number they never agreed to.
 *
 * Two things separate this from the writing assistant:
 *
 *   It sends real data. PromptScrubber exists so template rewriting never sees
 *   a client's name; extraction cannot work that way, because the name is the
 *   thing being read. That is why the feature is off until a workspace opts in.
 *
 *   It is schema-constrained rather than prompt-constrained. `output_config`
 *   pins the reply to a JSON Schema at the API level, so there is no fence to
 *   strip and no invented field to defend against.
 */
class InvoiceExtractor
{
    /** Matches FILESYSTEM_MAX_UPLOAD_MB; the API's own ceiling is far higher. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const ACCEPTED = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly AiService $ai = new AiService(),
        private readonly AiUsage $usage = new AiUsage(),
    ) {
    }

    /**
     * Is this workspace allowed to send documents to Anthropic?
     */
    public static function isEnabledFor(int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT ai_extraction_enabled FROM organizations WHERE id = ? LIMIT 1'
        );
        $statement->execute([$tenantId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Record consent. Deliberately not reversible by accident: turning it off
     * clears the flag but keeps the record that it was once on.
     */
    public function setEnabled(int $tenantId, int $userId, bool $enabled, ?DateTimeImmutable $now = null): void
    {
        $now ??= Clock::now();

        $statement = Database::connection()->prepare(
            'UPDATE organizations
             SET ai_extraction_enabled = ?,
                 ai_extraction_consented_at = CASE WHEN ? = 1 THEN ? ELSE ai_extraction_consented_at END,
                 ai_extraction_consented_by = CASE WHEN ? = 1 THEN ? ELSE ai_extraction_consented_by END
             WHERE id = ?'
        );
        $statement->execute([
            $enabled ? 1 : 0,
            $enabled ? 1 : 0,
            Clock::toDatabase($now),
            $enabled ? 1 : 0,
            $userId,
            $tenantId,
        ]);
    }

    /**
     * The shape the reply must take.
     *
     * Every field is nullable on purpose. An invoice that genuinely has no
     * client email should come back with null rather than with something
     * plausible, and the form then asks the user for it. Guessing is worse than
     * an empty box.
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'invoice_number', 'client_name', 'client_email', 'amount',
                'currency', 'issue_date', 'due_date', 'confidence', 'notes',
            ],
            'properties' => [
                'invoice_number' => [
                    'type' => ['string', 'null'],
                    'description' => 'The invoice number or reference, exactly as printed.',
                ],
                'client_name' => [
                    'type' => ['string', 'null'],
                    'description' => 'Who is being billed — the recipient, not the sender.',
                ],
                'client_email' => [
                    'type' => ['string', 'null'],
                    'description' => 'The recipient\'s email address if one appears. Null if not.',
                ],
                'amount' => [
                    'type' => ['string', 'null'],
                    'description' => 'The total due, digits and decimal point only, no symbol or thousands separator.',
                ],
                'currency' => [
                    'type' => ['string', 'null'],
                    'description' => 'ISO 4217 code, e.g. USD, GBP, EUR. Infer from the symbol if it is unambiguous.',
                ],
                'issue_date' => [
                    'type' => ['string', 'null'],
                    'description' => 'Date issued as YYYY-MM-DD.',
                ],
                'due_date' => [
                    'type' => ['string', 'null'],
                    'description' => 'Date payment is due as YYYY-MM-DD. If only terms are given (e.g. Net 30), compute it from the issue date.',
                ],
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['high', 'medium', 'low'],
                    'description' => 'How legible and unambiguous the document was.',
                ],
                'notes' => [
                    'type' => ['string', 'null'],
                    'description' => 'Anything the user should check, such as an ambiguous date format. Null if nothing.',
                ],
            ],
        ];
    }

    /**
     * Read one document.
     *
     * @return array{ok:bool, draft:?array, error:?string, confidence:?string, notes:?string, warnings:array}
     */
    public function extract(int $tenantId, string $path, string $originalName, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        if (!self::isEnabledFor($tenantId)) {
            return $this->failure('Reading invoice documents is switched off for this workspace.');
        }

        if (!ToneAssistService::isConfigured()) {
            return $this->failure('Document reading is not configured on this install.');
        }

        if (!is_file($path)) {
            return $this->failure('That file could not be read.');
        }

        if (filesize($path) > self::MAX_BYTES) {
            return $this->failure('That file is larger than 10 MB.');
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (!in_array($mimeType, self::ACCEPTED, true)) {
            return $this->failure('Upload a PDF or a photo of the invoice. That file is ' . $mimeType . '.');
        }

        $allowance = $this->usage->allowance($tenantId, $now);

        if (!$allowance['allowed']) {
            return $this->failure(
                'That is all ' . $allowance['limit'] . ' AI calls for today. The budget covers '
                . 'reading documents and rewriting reminders together, and it resets on a rolling '
                . 'day. Add this invoice by hand in the meantime.'
            );
        }

        $startedAt = microtime(true);

        try {
            $result = $this->ai->extractFromDocument(
                $this->prompt($now),
                $path,
                self::schema(),
                ['max_tokens' => 2000]
            );
        } catch (Throwable $exception) {
            error_log('[Duely] Invoice extraction failed: ' . $exception->getMessage());

            // A failed call still cost something, so it still counts.
            $this->usage->record(
                $tenantId, 'invoice_extract', 'unknown', [], 'failed', 'request_failed', $startedAt, $now
            );

            return $this->failure('That document could not be read. Add the invoice by hand instead.');
        }

        $this->usage->record(
            $tenantId,
            'invoice_extract',
            $result['model'],
            $result,
            'accepted',
            null,
            $startedAt,
            $now
        );

        [$draft, $warnings] = $this->normalise($result['data']);

        return [
            'ok' => true,
            'draft' => $draft,
            'error' => null,
            'confidence' => (string) ($result['data']['confidence'] ?? 'low'),
            'notes' => $result['data']['notes'] ?? null,
            'warnings' => $warnings,
        ];
    }

    // -------------------------------------------------------------- internals

    private function prompt(DateTimeImmutable $now): string
    {
        return <<<PROMPT
        Read this invoice and report the fields in the required shape.

        You are reading it on behalf of the person who SENT it, so the client is
        the party being billed — the "bill to" or "to" party — never the sender.

        Rules:
        - Report only what the document actually shows. If a field is not there,
          report null. Do not infer a plausible value.
        - Dates: use YYYY-MM-DD. An ambiguous numeric date like 03/04/2026 is
          the single most damaging thing to get wrong here, because it decides
          when a reminder is sent. If the order is genuinely ambiguous, pick the
          reading the rest of the document supports, lower the confidence, and
          say so in notes.
        - If only payment terms are given (Net 30, due on receipt), compute the
          due date from the issue date and say so in notes.
        - Amount: the total payable, not a subtotal or a line item.
        - Today's date is {$now->format('Y-m-d')}, for resolving relative terms.
        PROMPT;
    }

    /**
     * Bring the reply into the shape the invoice form expects, and collect
     * anything the user should look at twice.
     *
     * @return array{0:array<string,mixed>, 1:array<int,string>}
     */
    private function normalise(array $data): array
    {
        $warnings = [];

        $amount = trim((string) ($data['amount'] ?? ''));
        if ($amount !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            $warnings[] = 'The amount did not come back as a plain number. Check it.';
            $amount = '';
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $warnings[] = 'The currency was not a three-letter code. Check it.';
            $currency = '';
        }

        $email = trim((string) ($data['client_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'The email address did not look valid. Check it.';
            $email = '';
        }

        $dates = [];
        foreach (['issue_date', 'due_date'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $dates[$field] = $this->validDate($value) ? $value : '';

            if ($value !== '' && $dates[$field] === '') {
                $warnings[] = 'The ' . str_replace('_', ' ', $field) . ' was not a real date. Check it.';
            }
        }

        if ($dates['due_date'] === '') {
            $warnings[] = 'No due date was found. Reminders are timed from it, so it is required.';
        }

        return [[
            'number' => trim((string) ($data['invoice_number'] ?? '')),
            'client_name' => trim((string) ($data['client_name'] ?? '')),
            'client_email' => $email,
            'amount' => $amount,
            'currency' => $currency,
            'issue_date' => $dates['issue_date'],
            'due_date' => $dates['due_date'],
        ], $warnings];
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }

    private function failure(string $message): array
    {
        return [
            'ok' => false,
            'draft' => null,
            'error' => $message,
            'confidence' => null,
            'notes' => null,
            'warnings' => [],
        ];
    }
}
