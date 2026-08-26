<?php

namespace Keel\App\Controllers;

use Keel\App\Services\InvoiceExtractor;
use Keel\App\Services\TenantContext;
use Keel\Core\Activity;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * Reading an invoice document into a draft.
 *
 * Nothing here creates an invoice. The endpoint returns a draft that the
 * browser puts into the ordinary new-invoice form, and the user saves it the
 * normal way — through the same validation every other invoice goes through.
 *
 * The uploaded file is deleted as soon as it has been read. Duely does not need
 * to keep a copy of the client's invoice, and keeping one would mean holding
 * somebody else's document for no reason.
 */
class InvoiceExtractionController extends Controller
{
    public function __construct(private readonly InvoiceExtractor $extractor = new InvoiceExtractor())
    {
    }

    /**
     * GET /api/invoices/extraction/status
     */
    public function status(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->json([
            'enabled' => InvoiceExtractor::isEnabledFor($tenantId),
            'configured' => \Keel\App\Services\ToneAssistService::isConfigured(),
        ]);
    }

    /**
     * POST /api/invoices/extraction/consent
     *
     * Turning this on is the moment a workspace agrees that invoice documents
     * may be sent to Anthropic, so it is a deliberate action with its own
     * audit entry rather than a checkbox saved with something else.
     */
    public function consent(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOLEAN);

        $this->extractor->setEnabled($tenantId, (int) Auth::id(), $enabled);

        Activity::log(
            $enabled ? 'invoice_extraction.enabled' : 'invoice_extraction.disabled',
            'Organization',
            $tenantId
        );

        $this->json(['enabled' => $enabled]);
    }

    /**
     * POST /api/invoices/extract
     */
    public function extract(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['error' => $this->uploadError($file['error'] ?? UPLOAD_ERR_NO_FILE)], 422);
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');

        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            $this->json(['error' => 'That upload did not arrive intact. Try again.'], 422);
        }

        $result = $this->extractor->extract(
            $tenantId,
            $temporaryPath,
            (string) ($file['name'] ?? 'invoice')
        );

        // The document has been read. There is no reason to keep it.
        @unlink($temporaryPath);

        if (!$result['ok']) {
            $this->json(['error' => $result['error']], 422);
        }

        // The filename is logged, never the contents, and never the extracted
        // client details — the audit trail records that a document was read,
        // not what was in it.
        Activity::log('invoice.extracted', 'Invoice', null, [
            'confidence' => $result['confidence'],
            'warnings' => count($result['warnings']),
        ]);

        $this->json([
            'draft' => $result['draft'],
            'confidence' => $result['confidence'],
            'notes' => $result['notes'],
            'warnings' => $result['warnings'],
            // Said out loud, because the whole promise of this screen is that
            // nothing is written until the user says so.
            'saved' => false,
        ]);
    }

    // -------------------------------------------------------------- internals

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large. The limit is 10 MB.',
            UPLOAD_ERR_PARTIAL => 'That upload was cut short. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose an invoice to read.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not hold the file long enough to read it.',
            default => 'That file could not be uploaded.',
        };
    }
}
