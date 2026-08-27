<?php

namespace Keel\App\Services;

use Keel\App\Models\Client;
use Keel\App\Services\Timezones;
use Keel\App\Models\Invoice;
use Keel\Core\Database;
use Throwable;

/**
 * Reads a spreadsheet export into clients and invoices.
 *
 * Three rules shape this class:
 *
 *  1. One bad row never costs the user the other forty-nine. Validation is
 *     per-row; valid rows commit and invalid rows come back with the line
 *     number and a plain reason.
 *  2. Nothing commits on upload. preview() and validate() are read-only, and
 *     commit() only runs once the user has seen the mapping and confirmed.
 *  3. Re-importing the same file is safe. Invoices match on
 *     (tenant_id, number) and clients on (tenant_id, email), so a second run
 *     updates in place instead of duplicating.
 */
class CsvImporter
{
    /**
     * Rows shown in the preview step before the user maps columns.
     */
    public const PREVIEW_ROWS = 10;

    private const MAX_ROWS = 5000;

    private const STATUS_ALIASES = [
        'open' => Invoice::STATUS_OPEN,
        'unpaid' => Invoice::STATUS_OPEN,
        'outstanding' => Invoice::STATUS_OPEN,
        'due' => Invoice::STATUS_OPEN,
        'sent' => Invoice::STATUS_OPEN,
        'overdue' => Invoice::STATUS_OPEN,
        'pending' => Invoice::STATUS_OPEN,
        'paid' => Invoice::STATUS_PAID,
        'complete' => Invoice::STATUS_PAID,
        'completed' => Invoice::STATUS_PAID,
        'settled' => Invoice::STATUS_PAID,
        'closed' => Invoice::STATUS_PAID,
        'yes' => Invoice::STATUS_PAID,
        'void' => Invoice::STATUS_VOID,
        'voided' => Invoice::STATUS_VOID,
        'cancelled' => Invoice::STATUS_VOID,
        'canceled' => Invoice::STATUS_VOID,
        'draft' => Invoice::STATUS_VOID,
    ];

    // ------------------------------------------------------------- step one

    /**
     * Parse the file and show the user what we found, without touching the
     * database. Includes the detected mapping and a date-locale guess.
     *
     * @return array{
     *     headers:string[], rows:array<int, string[]>, total_rows:int,
     *     mapping:array<string,int|null>, fields:array, delimiter:string,
     *     has_header_row:bool, detected_locale:string, ambiguous_dates:bool,
     *     truncated:bool
     * }
     */
    public function preview(string $csv): array
    {
        $delimiter = $this->detectDelimiter($csv);
        $rows = $this->parseRows($csv, $delimiter);

        if ($rows === []) {
            return [
                'headers' => [],
                'rows' => [],
                'total_rows' => 0,
                'mapping' => [],
                'fields' => ColumnMapper::fields(),
                'delimiter' => $delimiter,
                'has_header_row' => false,
                'detected_locale' => DateParser::LOCALE_AUTO,
                'ambiguous_dates' => false,
                'truncated' => false,
            ];
        }

        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $hasHeaderRow = ColumnMapper::looksLikeHeaderRow($rows[0]);
        $headers = $hasHeaderRow
            ? array_map(static fn ($cell): string => trim((string) $cell), $rows[0])
            : $this->syntheticHeaders($rows[0]);

        $dataRows = $hasHeaderRow ? array_slice($rows, 1) : $rows;
        $mapping = ColumnMapper::detect($headers);

        // Sample the mapped due-date column to guess the writer's locale.
        $dueIndex = $mapping['due_date'] ?? null;
        $samples = [];
        $ambiguous = false;

        if ($dueIndex !== null) {
            foreach (array_slice($dataRows, 0, 40) as $row) {
                $value = (string) ($row[$dueIndex] ?? '');
                $samples[] = $value;

                if (DateParser::isAmbiguous($value)) {
                    $ambiguous = true;
                }
            }
        }

        return [
            'headers' => $headers,
            'rows' => array_slice($dataRows, 0, self::PREVIEW_ROWS),
            'total_rows' => count($dataRows),
            'mapping' => $mapping,
            'fields' => ColumnMapper::fields(),
            'delimiter' => $delimiter,
            'has_header_row' => $hasHeaderRow,
            'detected_locale' => DateParser::detectLocale($samples),
            'ambiguous_dates' => $ambiguous,
            'truncated' => $truncated,
        ];
    }

    // ------------------------------------------------------------- step two

    /**
     * Check every row against the chosen mapping. Read-only: this is what the
     * confirm screen is built from.
     *
     * @param array<string, int|null> $mapping
     * @return array{
     *     valid:array<int, array>, errors:array<int, array{line:int, reason:string, values:array}>,
     *     summary:array{total:int, valid:int, invalid:int, new_invoices:int, updated_invoices:int,
     *                   new_clients:int, matched_clients:int, currencies:array<string,int>}
     * }
     */
    public function validate(int $tenantId, string $csv, array $mapping, string $locale = DateParser::LOCALE_AUTO, bool $hasHeaderRow = true): array
    {
        $delimiter = $this->detectDelimiter($csv);
        $rows = array_slice($this->parseRows($csv, $delimiter), 0, self::MAX_ROWS);

        if ($rows === []) {
            return $this->emptyValidation();
        }

        $offset = $hasHeaderRow ? 1 : 0;
        $dataRows = array_slice($rows, $offset);

        $valid = [];
        $errors = [];
        $currencies = [];

        // Track what a commit would do, so the confirm screen can say
        // "42 new, 6 updated" rather than just "48 rows".
        $seenNumbers = [];
        $newInvoices = 0;
        $updatedInvoices = 0;
        $newClientEmails = [];
        $matchedClients = 0;

        foreach ($dataRows as $index => $row) {
            // Line number as the user sees it in their spreadsheet.
            $line = $index + $offset + 1;

            if ($this->isBlankRow($row)) {
                continue;
            }

            $parsed = $this->parseRow($row, $mapping, $locale);

            if ($parsed['error'] !== null) {
                $errors[] = [
                    'line' => $line,
                    'reason' => $parsed['error'],
                    'values' => $this->summariseRow($row, $mapping),
                ];
                continue;
            }

            $record = $parsed['record'];
            $number = $record['number'];

            // A file that repeats an invoice number would silently overwrite
            // itself, so the later occurrence is reported instead.
            if (isset($seenNumbers[$number])) {
                $errors[] = [
                    'line' => $line,
                    'reason' => 'Invoice ' . $number . ' also appears on line ' . $seenNumbers[$number] . ' of this file.',
                    'values' => $this->summariseRow($row, $mapping),
                ];
                continue;
            }

            $seenNumbers[$number] = $line;
            $record['line'] = $line;

            $existing = Invoice::findByNumber($tenantId, $number);
            $record['action'] = $existing === null ? 'create' : 'update';
            $existing === null ? $newInvoices++ : $updatedInvoices++;

            $email = $record['client_email'];

            if (Client::findByEmail($tenantId, $email) !== null) {
                $matchedClients++;
            } else {
                $newClientEmails[$email] = true;
            }

            $currency = $record['currency'];
            $currencies[$currency] = ($currencies[$currency] ?? 0) + 1;

            $valid[] = $record;
        }

        return [
            'valid' => $valid,
            'errors' => $errors,
            'summary' => [
                'total' => count($valid) + count($errors),
                'valid' => count($valid),
                'invalid' => count($errors),
                'new_invoices' => $newInvoices,
                'updated_invoices' => $updatedInvoices,
                'new_clients' => count($newClientEmails),
                'matched_clients' => $matchedClients,
                'currencies' => $currencies,
            ],
        ];
    }

    // ----------------------------------------------------------- step three

    /**
     * Write the valid rows. Invalid rows are reported, never a reason to stop.
     *
     * Each row commits in its own transaction: a row that fails at the database
     * layer is reported like any other bad row while its neighbours still land.
     *
     * @param array<string, int|null> $mapping
     * @return array{
     *     imported:int, updated:int, created:int, clients_created:int,
     *     clients_matched:int, errors:array<int, array{line:int, reason:string, values:array}>,
     *     summary:array
     * }
     */
    public function commit(int $tenantId, string $csv, array $mapping, string $locale = DateParser::LOCALE_AUTO, bool $hasHeaderRow = true): array
    {
        $validation = $this->validate($tenantId, $csv, $mapping, $locale, $hasHeaderRow);

        $created = 0;
        $updated = 0;
        $clientsCreated = 0;
        $clientsMatched = 0;
        $errors = $validation['errors'];

        // Resolved once, not per row: it is the same for every client in the
        // file and the query is not free.
        $workspaceTimezone = Timezones::forWorkspace($tenantId);

        $connection = Database::connection();

        foreach ($validation['valid'] as $record) {
            $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

            try {
                $existingClient = Client::findByEmail($tenantId, $record['client_email']);

                if ($existingClient !== null) {
                    $clientId = (int) $existingClient['id'];
                    $clientsMatched++;

                    // Fill in details the existing client is missing, without
                    // overwriting anything the user has already curated.
                    $this->enrichClient($tenantId, $existingClient, $record);
                } else {
                    $clientId = Client::create($tenantId, [
                        'name' => $record['client_name'],
                        'email' => $record['client_email'],
                        'company' => $record['client_company'],
                        // A CSV with no timezone column lands on the workspace
                        // default, not on UTC.
                        'timezone' => $record['client_timezone'] ?? $workspaceTimezone,
                    ]);
                    $clientsCreated++;
                }

                $attributes = [
                    'client_id' => $clientId,
                    'number' => $record['number'],
                    'amount_cents' => $record['amount_cents'],
                    'currency' => $record['currency'],
                    'due_date' => $record['due_date'],
                    'issue_date' => $record['issue_date'],
                    'status' => $record['status'],
                    'payment_url' => $record['payment_url'],
                    'notes' => $record['notes'],
                ];

                // Idempotent on (tenant_id, number): re-importing updates.
                $existingInvoice = Invoice::findByNumber($tenantId, $record['number']);

                if ($existingInvoice !== null) {
                    Invoice::update($tenantId, (int) $existingInvoice['id'], $attributes);
                    $updated++;
                } else {
                    Invoice::create($tenantId, $attributes);
                    $created++;
                }

                if ($openedTransaction) {
                    $connection->commit();
                }
            } catch (Throwable $exception) {
                if ($openedTransaction && $connection->inTransaction()) {
                    $connection->rollBack();
                }

                $errors[] = [
                    'line' => $record['line'],
                    'reason' => $this->humanDatabaseError($exception),
                    'values' => ['number' => $record['number'], 'email' => $record['client_email']],
                ];
            }
        }

        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return [
            'imported' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'clients_created' => $clientsCreated,
            'clients_matched' => $clientsMatched,
            'errors' => $errors,
            'summary' => $validation['summary'],
        ];
    }

    // ------------------------------------------------------- row processing

    /**
     * Turn one raw row into a validated record, or a reason it cannot be used.
     *
     * @param string[] $row
     * @param array<string, int|null> $mapping
     * @return array{record:?array, error:?string}
     */
    private function parseRow(array $row, array $mapping, string $locale): array
    {
        $read = static function (string $field) use ($row, $mapping): string {
            $index = $mapping[$field] ?? null;

            return $index === null ? '' : trim((string) ($row[$index] ?? ''));
        };

        $number = $read('number');

        if ($number === '') {
            return ['record' => null, 'error' => 'Missing invoice number.'];
        }

        if (mb_strlen($number) > 64) {
            return ['record' => null, 'error' => 'Invoice number is longer than 64 characters.'];
        }

        $email = strtolower($read('client_email'));

        if ($email === '') {
            return ['record' => null, 'error' => 'Missing client email.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['record' => null, 'error' => '"' . $email . '" is not a valid email address.'];
        }

        $rawAmount = $read('amount');

        if ($rawAmount === '') {
            return ['record' => null, 'error' => 'Missing amount.'];
        }

        $money = MoneyParser::parse($rawAmount);

        if ($money === null) {
            return ['record' => null, 'error' => 'Could not read "' . $rawAmount . '" as an amount.'];
        }

        if ($money['cents'] === 0) {
            return ['record' => null, 'error' => 'Amount is zero, so there is nothing to chase.'];
        }

        if ($money['cents'] < 0) {
            return ['record' => null, 'error' => 'Amount is negative. Credit notes cannot be chased.'];
        }

        $rawDue = $read('due_date');

        if ($rawDue === '') {
            return ['record' => null, 'error' => 'Missing due date.'];
        }

        $dueDate = DateParser::parseToDateString($rawDue, $locale);

        if ($dueDate === null) {
            return ['record' => null, 'error' => 'Could not read "' . $rawDue . '" as a date.'];
        }

        $rawIssue = $read('issue_date');
        $issueDate = $rawIssue === '' ? null : DateParser::parseToDateString($rawIssue, $locale);

        if ($issueDate !== null && $issueDate > $dueDate) {
            return ['record' => null, 'error' => 'Issue date ' . $issueDate . ' is after the due date ' . $dueDate . '.'];
        }

        $currency = $this->resolveCurrency($read('currency'), $money['currency']);

        if ($currency === null) {
            return ['record' => null, 'error' => 'Could not read "' . $read('currency') . '" as a currency code.'];
        }

        $name = $read('client_name');

        if ($name === '') {
            // A usable fallback beats rejecting the row over a cosmetic field.
            $name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
        }

        $paymentUrl = $read('payment_url');

        if ($paymentUrl !== '' && !filter_var($paymentUrl, FILTER_VALIDATE_URL)) {
            return ['record' => null, 'error' => '"' . $paymentUrl . '" is not a valid link.'];
        }

        // Rejected here rather than left for ChaseScheduler to fall back on.
        // That fallback is a last-resort guard against corrupt data; a typo in a
        // spreadsheet is not corrupt data, and silently treating it as UTC moves
        // every reminder for that client by hours without telling anybody.
        $timezone = $read('timezone');

        if ($timezone !== '' && !Timezones::isValid($timezone)) {
            return [
                'record' => null,
                'error' => '"' . $timezone . '" is not a timezone Duely recognises. '
                    . 'Use an IANA name like America/Denver.',
            ];
        }

        return [
            'record' => [
                'number' => $number,
                'client_email' => $email,
                'client_name' => mb_substr($name, 0, 255),
                'client_company' => mb_substr($read('client_company'), 0, 255) ?: null,
                'amount_cents' => $money['cents'],
                'currency' => $currency,
                'due_date' => $dueDate,
                'issue_date' => $issueDate,
                'status' => $this->resolveStatus($read('status')),
                'payment_url' => $paymentUrl ?: null,
                // Empty means "no opinion". The commit step fills in the
                // workspace default, which it knows and this method does not.
                'client_timezone' => $timezone ?: null,
                'notes' => $read('notes') ?: null,
            ],
            'error' => null,
        ];
    }

    /**
     * An explicit currency column wins; otherwise use whatever symbol the
     * amount carried; otherwise fall back to USD.
     */
    private function resolveCurrency(string $explicit, ?string $fromAmount): ?string
    {
        $explicit = strtoupper(trim($explicit));

        if ($explicit !== '') {
            if (preg_match('/^[A-Z]{3}$/', $explicit) === 1) {
                return $explicit;
            }

            // A symbol in the currency column is still a clear intent.
            $symbolCurrency = MoneyParser::parse($explicit . '1')['currency'] ?? null;

            return $symbolCurrency ?? null;
        }

        return $fromAmount ?? 'USD';
    }

    private function resolveStatus(string $raw): string
    {
        $raw = strtolower(trim($raw));

        if ($raw === '') {
            return Invoice::STATUS_OPEN;
        }

        // An unrecognised status is not worth rejecting a row over; an invoice
        // we wrongly treat as open is visible and fixable, unlike a lost row.
        return self::STATUS_ALIASES[$raw] ?? Invoice::STATUS_OPEN;
    }

    /**
     * Add company or name to a matched client when the existing record has
     * neither, so an import improves the data without trampling it.
     */
    private function enrichClient(int $tenantId, array $client, array $record): void
    {
        $updates = [];

        if (trim((string) $client['company']) === '' && $record['client_company'] !== null) {
            $updates['company'] = $record['client_company'];
        }

        // Replace a placeholder name (the email) with a real one.
        if (strcasecmp(trim((string) $client['name']), (string) $client['email']) === 0
            && $record['client_name'] !== '') {
            $updates['name'] = $record['client_name'];
        }

        // An explicit timezone in the file fills in a client still sitting on
        // the default, but never overwrites one somebody chose. The same rule as
        // company and name: improve the data, do not trample it.
        if (($record['client_timezone'] ?? null) !== null
            && trim((string) $client['timezone']) === Timezones::DEFAULT) {
            $updates['timezone'] = $record['client_timezone'];
        }

        if ($updates !== []) {
            Client::update($tenantId, (int) $client['id'], $updates);
        }
    }

    // ------------------------------------------------------------ CSV parsing

    /**
     * Split the file into rows, coping with the encodings and line endings
     * spreadsheets emit.
     *
     * @return array<int, string[]>
     */
    private function parseRows(string $csv, string $delimiter): array
    {
        $csv = $this->normaliseEncoding($csv);

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // fgetcsv reports a blank line as [null]; keep it out of the data.
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(
                static fn ($cell): string => $cell === null ? '' : trim((string) $cell),
                $row
            );
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Strip a BOM, normalise line endings, and bring common non-UTF-8 exports
     * into UTF-8 so names with accents survive.
     */
    private function normaliseEncoding(string $csv): string
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        if (!mb_check_encoding($csv, 'UTF-8')) {
            // Excel on Windows commonly writes CP1252.
            $converted = @mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');

            if (is_string($converted) && $converted !== '') {
                $csv = $converted;
            }
        }

        return str_replace(["\r\n", "\r"], "\n", $csv);
    }

    /**
     * Pick the delimiter by seeing which one splits the header row into the
     * most fields consistently.
     */
    private function detectDelimiter(string $csv): string
    {
        $sample = substr($this->normaliseEncoding($csv), 0, 8192);
        $lines = array_slice(array_filter(explode("\n", $sample), static fn ($l): bool => trim($l) !== ''), 0, 5);

        if ($lines === []) {
            return ',';
        }

        $best = ',';
        $bestScore = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = [];

            foreach ($lines as $line) {
                $counts[] = count(str_getcsv($line, $candidate, '"', '\\'));
            }

            $columns = min($counts);

            // Reward a delimiter that yields the same column count on every
            // line — the hallmark of the real one.
            $consistent = count(array_unique($counts)) === 1;
            $score = $columns + ($consistent ? 2 : 0);

            if ($columns > 1 && $score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param string[] $firstRow
     * @return string[]
     */
    private function syntheticHeaders(array $firstRow): array
    {
        $headers = [];

        foreach (array_keys($firstRow) as $index) {
            $headers[] = 'Column ' . ($index + 1);
        }

        return $headers;
    }

    /**
     * @param string[] $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The handful of mapped values worth echoing back beside an error, so the
     * user can find the row without opening the file.
     *
     * @param string[] $row
     * @param array<string, int|null> $mapping
     */
    private function summariseRow(array $row, array $mapping): array
    {
        $summary = [];

        foreach (['number', 'client_email', 'amount', 'due_date'] as $field) {
            $index = $mapping[$field] ?? null;

            if ($index !== null) {
                $summary[$field] = mb_substr(trim((string) ($row[$index] ?? '')), 0, 60);
            }
        }

        return $summary;
    }

    private function humanDatabaseError(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, '1062')) {
            return 'That invoice number already exists and could not be updated.';
        }

        if (str_contains($message, '1406') || str_contains($message, 'Data too long')) {
            return 'One of the values is too long for its field.';
        }

        return 'This row could not be saved.';
    }

    private function emptyValidation(): array
    {
        return [
            'valid' => [],
            'errors' => [],
            'summary' => [
                'total' => 0, 'valid' => 0, 'invalid' => 0,
                'new_invoices' => 0, 'updated_invoices' => 0,
                'new_clients' => 0, 'matched_clients' => 0,
                'currencies' => [],
            ],
        ];
    }
}
