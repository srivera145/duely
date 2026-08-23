<?php

namespace Keel\App\Controllers;

use Keel\App\Services\ColumnMapper;
use Keel\App\Services\CsvImporter;
use Keel\App\Services\DateParser;
use Keel\App\Services\ImportStaging;
use Keel\App\Services\MoneyParser;
use Keel\App\Services\TenantContext;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;
use RuntimeException;

/**
 * The CSV import wizard: upload, preview, map, validate, confirm, commit.
 *
 * Upload never writes an invoice. The file is staged, previewed, and validated
 * as separate read-only steps; only commit() writes, and only after the user
 * has seen the mapping and the validation result and pressed the button.
 */
class ImportController extends Controller
{
    public function __construct(private readonly CsvImporter $importer = new CsvImporter())
    {
    }

    /**
     * GET /invoices/import
     */
    public function show(Request $request): void
    {
        TenantContext::requireId();

        $this->view('invoices.import', [
            'title' => 'Import invoices - Duely',
            'metaDescription' => 'Bring your invoices in from a spreadsheet.',
            'fields' => ColumnMapper::fields(),
            'previewRows' => CsvImporter::PREVIEW_ROWS,
        ]);
    }

    /**
     * POST /api/invoices/import/upload — stage the file and return a preview.
     *
     * Deliberately does not import anything.
     */
    public function upload(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        try {
            $stored = ImportStaging::store($tenantId, $_FILES['file'] ?? []);
        } catch (RuntimeException $exception) {
            $this->json(['error' => $exception->getMessage()], 422);
        }

        $csv = ImportStaging::contents($tenantId, $stored['token']);
        $preview = $this->importer->preview($csv);

        if ($preview['total_rows'] === 0) {
            ImportStaging::discard($tenantId, $stored['token']);
            $this->json(['error' => 'That file has no data rows in it.'], 422);
        }

        $this->json([
            'token' => $stored['token'],
            'original_name' => $stored['original_name'],
            'headers' => $preview['headers'],
            'rows' => $preview['rows'],
            'total_rows' => $preview['total_rows'],
            'mapping' => $preview['mapping'],
            'fields' => $preview['fields'],
            'has_header_row' => $preview['has_header_row'],
            'detected_locale' => $preview['detected_locale'],
            'ambiguous_dates' => $preview['ambiguous_dates'],
            'truncated' => $preview['truncated'],
            'missing_required' => ColumnMapper::missingRequired($preview['mapping']),
            // Said plainly, because an import screen that might already have
            // written something is the thing users are afraid of.
            'committed' => false,
        ]);
    }

    /**
     * POST /api/invoices/import/validate — dry run against the chosen mapping.
     */
    public function validate(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        [$csv, $mapping, $locale, $hasHeaderRow] = $this->wizardInput($request, $tenantId);

        $missing = ColumnMapper::missingRequired($mapping);

        if ($missing !== []) {
            $this->json([
                'error' => 'Map these columns before continuing: ' . implode(', ', $missing) . '.',
                'missing_required' => $missing,
            ], 422);
        }

        $result = $this->importer->validate($tenantId, $csv, $mapping, $locale, $hasHeaderRow);

        $this->json([
            'summary' => $result['summary'],
            'errors' => $result['errors'],
            // A sample of what will land, so the user can sanity-check the
            // parsing before committing rather than after.
            'sample' => array_map(
                [$this, 'presentRecord'],
                array_slice($result['valid'], 0, CsvImporter::PREVIEW_ROWS)
            ),
            'committed' => false,
        ]);
    }

    /**
     * POST /api/invoices/import/commit — the only step that writes.
     */
    public function commit(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        [$csv, $mapping, $locale, $hasHeaderRow, $token] = $this->wizardInput($request, $tenantId, true);

        $missing = ColumnMapper::missingRequired($mapping);

        if ($missing !== []) {
            $this->json([
                'error' => 'Map these columns before importing: ' . implode(', ', $missing) . '.',
                'missing_required' => $missing,
            ], 422);
        }

        // The user has to say so explicitly; a stray request cannot import.
        if (!filter_var($request->input('confirmed', false), FILTER_VALIDATE_BOOL)) {
            $this->json(['error' => 'Confirm the import before it can run.'], 422);
        }

        $result = $this->importer->commit($tenantId, $csv, $mapping, $locale, $hasHeaderRow);

        Activity::log('invoices.imported', 'Invoice', null, [
            'created' => $result['created'],
            'updated' => $result['updated'],
            'failed' => count($result['errors']),
        ]);

        // The staged upload has done its job; do not leave client data on disk.
        ImportStaging::discard($tenantId, $token);

        $this->json([
            'imported' => $result['imported'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'clients_created' => $result['clients_created'],
            'clients_matched' => $result['clients_matched'],
            'errors' => $result['errors'],
            'summary' => $result['summary'],
            'committed' => true,
        ]);
    }

    /**
     * POST /api/invoices/import/cancel — drop a staged upload.
     */
    public function cancel(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $token = (string) $request->input('token', '');

        if ($token !== '') {
            try {
                ImportStaging::discard($tenantId, $token);
            } catch (RuntimeException) {
                // An invalid or already-removed token needs no explanation.
            }
        }

        $this->json(['discarded' => true]);
    }

    /**
     * GET /api/invoices/import/errors.csv — take the rejected rows away to fix.
     */
    public function downloadErrors(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        [$csv, $mapping, $locale, $hasHeaderRow] = $this->wizardInput($request, $tenantId);

        $result = $this->importer->validate($tenantId, $csv, $mapping, $locale, $hasHeaderRow);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Line', 'Reason', 'Invoice number', 'Client email', 'Amount', 'Due date'], ',', '"', '\\');

        foreach ($result['errors'] as $error) {
            fputcsv($handle, [
                $error['line'],
                $error['reason'],
                $error['values']['number'] ?? '',
                $error['values']['client_email'] ?? '',
                $error['values']['amount'] ?? '',
                $error['values']['due_date'] ?? '',
            ], ',', '"', '\\');
        }

        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        \Keel\Core\Response::raw($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="duely-import-errors.csv"',
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * Resolve the staged file and the mapping the user chose.
     *
     * The token is looked up under the caller's tenant directory, so a token
     * belonging to another tenant simply does not resolve to a file.
     *
     * @return array{0:string, 1:array<string,int|null>, 2:string, 3:bool, 4:string}
     */
    private function wizardInput(Request $request, int $tenantId, bool $withToken = false): array
    {
        $token = (string) $request->input('token', '');

        try {
            $csv = ImportStaging::contents($tenantId, $token);
        } catch (RuntimeException $exception) {
            $this->json(['error' => $exception->getMessage()], 422);
        }

        $mapping = $this->normaliseMapping($request->input('mapping', []));

        $locale = (string) $request->input('locale', DateParser::LOCALE_AUTO);
        $locale = in_array($locale, [DateParser::LOCALE_AUTO, DateParser::LOCALE_MDY, DateParser::LOCALE_DMY], true)
            ? $locale
            : DateParser::LOCALE_AUTO;

        $hasHeaderRow = filter_var($request->input('has_header_row', true), FILTER_VALIDATE_BOOL);

        return [$csv, $mapping, $locale, $hasHeaderRow, $token];
    }

    /**
     * Accept only known field names and integer column indexes, so nothing the
     * client sends reaches the importer unchecked.
     *
     * @return array<string, int|null>
     */
    private function normaliseMapping(mixed $raw): array
    {
        $mapping = [];
        $fields = ColumnMapper::fields();

        if (!is_array($raw)) {
            $raw = [];
        }

        foreach ($fields as $field => $definition) {
            $value = $raw[$field] ?? null;

            if ($value === null || $value === '' || $value === -1 || $value === '-1') {
                $mapping[$field] = null;
                continue;
            }

            $mapping[$field] = is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
        }

        return $mapping;
    }

    /**
     * Show a parsed record the way it will be stored, with money and dates
     * rendered so the user can spot a locale mistake before committing.
     */
    private function presentRecord(array $record): array
    {
        return [
            'line' => $record['line'],
            'action' => $record['action'],
            'number' => $record['number'],
            'client_name' => $record['client_name'],
            'client_email' => $record['client_email'],
            'amount_formatted' => MoneyParser::format($record['amount_cents'], $record['currency']),
            'due_date' => $record['due_date'],
            'status' => $record['status'],
        ];
    }
}
