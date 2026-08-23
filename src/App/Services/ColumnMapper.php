<?php

namespace Keel\App\Services;

/**
 * Guesses which spreadsheet column holds which invoice field.
 *
 * The guess is only ever a starting point — the import wizard shows it back to
 * the user as an editable mapping before anything is validated or committed.
 */
class ColumnMapper
{
    /**
     * The fields an import can populate.
     *
     * @return array<string, array{label:string, required:bool, help:string}>
     */
    public static function fields(): array
    {
        return [
            'number' => [
                'label' => 'Invoice number',
                'required' => true,
                'help' => 'Used to match re-imports, so the same file never duplicates.',
            ],
            'client_email' => [
                'label' => 'Client email',
                'required' => true,
                'help' => 'Duely matches an existing client on this before creating a new one.',
            ],
            'client_name' => [
                'label' => 'Client name',
                'required' => false,
                'help' => 'Defaults to the part before the @ if left unmapped.',
            ],
            'client_company' => [
                'label' => 'Client company',
                'required' => false,
                'help' => '',
            ],
            'amount' => [
                'label' => 'Amount',
                'required' => true,
                'help' => 'Currency symbols, commas and brackets are all fine.',
            ],
            'currency' => [
                'label' => 'Currency',
                'required' => false,
                'help' => 'Read from the amount when it carries a symbol.',
            ],
            'due_date' => [
                'label' => 'Due date',
                'required' => true,
                'help' => '',
            ],
            'issue_date' => [
                'label' => 'Issue date',
                'required' => false,
                'help' => '',
            ],
            'status' => [
                'label' => 'Status',
                'required' => false,
                'help' => 'open, paid, or void. Defaults to open.',
            ],
            'payment_url' => [
                'label' => 'Payment link',
                'required' => false,
                'help' => 'Included in reminders when present.',
            ],
            'notes' => [
                'label' => 'Notes',
                'required' => false,
                'help' => '',
            ],
        ];
    }

    /**
     * Header aliases, most specific first within each field.
     *
     * Matching is exact-after-normalisation first, then a contains pass, so
     * "Invoice Total" lands on amount rather than on number.
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'number' => [
            'invoice number', 'invoice no', 'invoice #', 'invoice num', 'invoice id',
            'inv number', 'inv no', 'inv #', 'number', 'no', 'num', 'reference',
            'ref', 'doc number', 'document number', 'invoice',
        ],
        'client_email' => [
            'client email', 'customer email', 'contact email', 'bill to email',
            'billing email', 'email address', 'e-mail', 'email',
        ],
        'client_name' => [
            'client name', 'customer name', 'contact name', 'bill to', 'billed to',
            'client', 'customer', 'contact', 'name',
        ],
        'client_company' => [
            'company name', 'company', 'organisation', 'organization', 'business',
            'account', 'account name',
        ],
        'amount' => [
            'amount due', 'total amount', 'invoice total', 'invoice amount',
            'balance due', 'grand total', 'amount', 'total', 'value', 'sum',
            'price', 'balance', 'subtotal',
        ],
        'currency' => [
            'currency code', 'currency', 'ccy',
        ],
        'due_date' => [
            'due date', 'date due', 'payment due', 'due on', 'due by', 'due',
            'expiry date', 'expires',
        ],
        'issue_date' => [
            'issue date', 'invoice date', 'date issued', 'issued on', 'created date',
            'date created', 'issued', 'date',
        ],
        'status' => [
            'invoice status', 'payment status', 'status', 'paid', 'state',
        ],
        'payment_url' => [
            'payment link', 'payment url', 'pay link', 'pay url', 'checkout url', 'link',
        ],
        'notes' => [
            'notes', 'note', 'description', 'memo', 'comment', 'comments', 'details',
        ],
    ];

    /**
     * Best-guess mapping of field name to column index.
     *
     * @param string[] $headers
     * @return array<string, int|null>
     */
    public static function detect(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $index => $header) {
            $normalised[$index] = self::normalise((string) $header);
        }

        $mapping = [];
        $claimed = [];

        // Pass one: exact matches, which are the trustworthy ones.
        foreach (self::ALIASES as $field => $aliases) {
            $mapping[$field] = null;

            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalised, true);

                if ($index !== false && !in_array($index, $claimed, true)) {
                    $mapping[$field] = (int) $index;
                    $claimed[] = (int) $index;
                    break;
                }
            }
        }

        // Pass two: substring matches for anything still unmapped.
        foreach (self::ALIASES as $field => $aliases) {
            if ($mapping[$field] !== null) {
                continue;
            }

            foreach ($aliases as $alias) {
                foreach ($normalised as $index => $header) {
                    if ($header === '' || in_array($index, $claimed, true)) {
                        continue;
                    }

                    if (str_contains($header, $alias)) {
                        $mapping[$field] = (int) $index;
                        $claimed[] = (int) $index;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Which required fields the mapping still leaves unset.
     *
     * @param array<string, int|null> $mapping
     * @return string[] field labels
     */
    public static function missingRequired(array $mapping): array
    {
        $missing = [];

        foreach (self::fields() as $field => $definition) {
            if ($definition['required'] && ($mapping[$field] ?? null) === null) {
                $missing[] = $definition['label'];
            }
        }

        return $missing;
    }

    /**
     * Does the first row look like headers rather than data?
     *
     * A header row is mostly non-numeric and matches at least one known alias.
     *
     * @param string[] $row
     */
    public static function looksLikeHeaderRow(array $row): bool
    {
        if ($row === []) {
            return false;
        }

        $numeric = 0;

        foreach ($row as $cell) {
            $cell = trim((string) $cell);

            if ($cell !== '' && preg_match('/^[\d.,$€£\s()-]+$/u', $cell) === 1) {
                $numeric++;
            }
        }

        // A row that is mostly numbers is data, whatever it is called.
        if ($numeric > count($row) / 2) {
            return false;
        }

        $mapping = self::detect($row);

        foreach ($mapping as $index) {
            if ($index !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, collapse punctuation and whitespace, drop a trailing "#".
     */
    private static function normalise(string $header): string
    {
        $header = str_replace(["\xEF\xBB\xBF", "\xC2\xA0"], ' ', $header);
        $header = strtolower(trim($header));
        $header = str_replace(['_', '-', '.', '/'], ' ', $header);
        $header = preg_replace('/[^\p{L}\p{N}#\s]/u', ' ', $header) ?? $header;
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return trim($header);
    }
}
