<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Chase;
use Keel\App\Models\Client;
use Keel\App\Models\Invoice;
use Keel\App\Services\ConnectService;
use Keel\App\Services\DateParser;
use Keel\App\Services\MoneyParser;
use Keel\App\Services\PaymentLinkService;
use Keel\App\Services\TenantContext;
use Keel\App\Services\Timezones;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * Invoice list, editor, and the JSON endpoints the list uses.
 *
 * Every read and write goes through the tenant-scoped models, so an id from
 * another tenant resolves to nothing rather than to someone else's invoice.
 */
class InvoiceController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * GET /invoices
     */
    public function index(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $filters = $this->filters($request);
        $page = max(1, (int) $request->input('page', 1));

        $this->view('invoices.index', [
            'title' => 'Invoices - Duely',
            'metaDescription' => 'Every invoice Duely is tracking, and where each chase has got to.',
            'invoices' => Invoice::listWithContext($tenantId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'tallies' => Invoice::statusTallies($tenantId),
            'outstanding' => Invoice::outstandingByCurrency($tenantId),
            'filters' => $filters,
            'page' => $page,
            'total' => Invoice::countWithFilters($tenantId, $filters),
            'perPage' => self::PER_PAGE,
            'clients' => Client::active($tenantId, 500),
        ]);
    }

    /**
     * GET /invoices/new
     */
    /**
     * The extracted fields carried over from a document read.
     *
     * Read one known key at a time and re-validated here, because a query
     * string is user input whatever put it there.
     *
     * @return array{values:array<string,string>, confidence:?string, notes:?string, warnings:array<int,string>}
     */
    private function draftFromQuery(Request $request): array
    {
        $values = [];

        foreach (['number', 'client_name', 'client_email', 'amount', 'currency', 'issue_date', 'due_date'] as $field) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '') {
                $values[$field] = mb_substr($value, 0, 255);
            }
        }

        $confidence = (string) $request->input('confidence', '');
        $warnings = array_values(array_filter(
            array_map(
                static fn ($w): string => mb_substr(trim((string) $w), 0, 200),
                (array) ($_GET['warning'] ?? [])
            ),
            static fn (string $w): bool => $w !== ''
        ));

        return [
            'values' => $values,
            'confidence' => in_array($confidence, ['high', 'medium', 'low'], true) ? $confidence : null,
            'notes' => mb_substr(trim((string) $request->input('notes', '')), 0, 300) ?: null,
            'warnings' => array_slice($warnings, 0, 6),
        ];
    }

    public function create(Request $request): void
    {
        $tenantId = TenantContext::requireId();

        $this->view('invoices.edit', [
            'title' => 'New invoice - Duely',
            'invoice' => null,
            'clients' => Client::active($tenantId, 500),
            'chase' => null,
            // A draft read off an uploaded document, if the user came that way.
            // It is only ever a suggestion in the form -- the save path is the
            // same one a hand-typed invoice takes, with the same validation.
            'draft' => $this->draftFromQuery($request),
            'canTakePayments' => (new ConnectService())->status($tenantId)['can_take_payments'],
            'workspacePaymentMode' => (new PaymentLinkService())->workspaceMode($tenantId),
        ]);
    }

    /**
     * GET /invoices/{id}/edit — the form.
     */
    public function edit(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $invoice = Invoice::withClient($tenantId, (int) $id);

        if ($invoice === null) {
            $this->notFound($request, 'That invoice does not exist.');
        }

        $this->view('invoices.edit', [
            'title' => 'Invoice ' . $invoice['number'] . ' - Duely',
            'invoice' => $invoice,
            'clients' => Client::active($tenantId, 500),
            'chase' => Chase::forInvoice($tenantId, (int) $invoice['id']),
            'canTakePayments' => (new ConnectService())->status($tenantId)['can_take_payments'],
            'workspacePaymentMode' => (new PaymentLinkService())->workspaceMode($tenantId),
        ]);
    }

    /**
     * GET /invoices/{id}/timeline — where this page used to live.
     *
     * A permanent redirect rather than a deletion. The old URL is in people's
     * browser history and may be in a bookmark, and 301 is what tells the
     * browser to stop asking. Kept indefinitely: it costs one route.
     */
    public function timelineRedirect(Request $request, string $id): never
    {
        Response::redirect('/invoices/' . (int) $id, 301);
    }

    /**
     * GET /invoices/{id} — the invoice: what has happened to it, and the
     * controls for what happens next.
     */
    public function show(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $timeline = (new \Keel\App\Services\InvoiceTimeline())->build($tenantId, (int) $id);

        if ($timeline === null) {
            $this->notFound($request, 'That invoice does not exist.');
        }

        $this->view('invoices.show', [
            'title' => 'Invoice ' . $timeline['invoice']['number'] . ' - Duely',
            'metaDescription' => 'Every reminder sent and every reply received for this invoice.',
            'invoice' => $timeline['invoice'],
            'chase' => $timeline['chase'],
            'rail' => $timeline['rail'],
            'events' => $timeline['events'],
            'sequences' => \Keel\App\Models\Sequence::active($tenantId),
            // What the client is about to receive. Worked out rather than
            // guessed at -- finding out from the client is not acceptable.
            'paymentPlan' => (new PaymentLinkService())->plan($tenantId, $timeline['invoice']),
            'timezone' => Timezones::forWorkspace($tenantId),
        ]);
    }

    /**
     * GET /api/invoices — the list as JSON, for live filtering.
     */
    public function listJson(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $filters = $this->filters($request);
        $page = max(1, (int) $request->input('page', 1));

        $invoices = Invoice::listWithContext($tenantId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->json([
            'invoices' => array_map([$this, 'presentRow'], $invoices),
            'tallies' => Invoice::statusTallies($tenantId),
            'total' => Invoice::countWithFilters($tenantId, $filters),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'filters' => $filters,
        ]);
    }

    /**
     * POST /api/invoices — create or update.
     */
    public function store(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $input = $this->invoiceInput($request);
        $invoiceId = (int) $request->input('id', 0);

        $errors = $this->validate($tenantId, $input, $invoiceId);

        if ($errors !== []) {
            $this->json(['errors' => $errors], 422);
        }

        $attributes = [
            'client_id' => $input['client_id'],
            'number' => $input['number'],
            'amount_cents' => $input['amount_cents'],
            'currency' => $input['currency'],
            'issue_date' => $input['issue_date'],
            'due_date' => $input['due_date'],
            'status' => $input['status'],
            'payment_url' => $input['payment_url'],
            'payment_link_mode' => $input['payment_link_mode'],
            'notes' => $input['notes'],
        ];

        if ($invoiceId > 0) {
            if (!Invoice::exists($tenantId, $invoiceId)) {
                $this->json(['errors' => ['number' => 'That invoice does not exist.']], 404);
            }

            // Marking an invoice paid should stop its chase, not leave it
            // emailing a client who has already paid.
            if ($input['status'] === Invoice::STATUS_PAID) {
                $attributes['paid_at'] = date('Y-m-d H:i:s');
                $this->pauseChaseForPayment($tenantId, $invoiceId);
            }

            Invoice::update($tenantId, $invoiceId, $attributes);
            Activity::log('invoice.updated', 'Invoice', $invoiceId, ['number' => $input['number']]);
        } else {
            $invoiceId = Invoice::create($tenantId, $attributes);
            Activity::log('invoice.created', 'Invoice', $invoiceId, ['number' => $input['number']]);
        }

        $this->json([
            'invoice' => $this->presentRow(Invoice::withClient($tenantId, $invoiceId) ?? []),
            'id' => $invoiceId,
        ]);
    }

    /**
     * POST /api/invoices/{id}/status — mark paid, void, or open again.
     */
    public function updateStatus(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $invoiceId = (int) $id;
        $status = strtolower(trim((string) $request->input('status', '')));

        if (!Invoice::exists($tenantId, $invoiceId)) {
            $this->json(['error' => 'That invoice does not exist.'], 404);
        }

        match ($status) {
            Invoice::STATUS_PAID => $this->markPaid($tenantId, $invoiceId),
            Invoice::STATUS_VOID => $this->markVoid($tenantId, $invoiceId),
            Invoice::STATUS_OPEN => Invoice::update($tenantId, $invoiceId, [
                'status' => Invoice::STATUS_OPEN,
                'paid_at' => null,
            ]),
            default => $this->json(['error' => 'Unknown status.'], 422),
        };

        Activity::log('invoice.status_changed', 'Invoice', $invoiceId, ['status' => $status]);

        $this->json(['invoice' => $this->presentRow(Invoice::withClient($tenantId, $invoiceId) ?? [])]);
    }

    /**
     * POST /api/invoices/{id}/delete
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $invoiceId = (int) $id;

        if (!Invoice::exists($tenantId, $invoiceId)) {
            $this->json(['error' => 'That invoice does not exist.'], 404);
        }

        Invoice::delete($tenantId, $invoiceId);
        Activity::log('invoice.deleted', 'Invoice', $invoiceId);

        $this->json(['deleted' => true]);
    }

    // -------------------------------------------------------------- internals

    private function markPaid(int $tenantId, int $invoiceId): void
    {
        Invoice::markPaid($tenantId, $invoiceId);
        $this->pauseChaseForPayment($tenantId, $invoiceId);
    }

    private function markVoid(int $tenantId, int $invoiceId): void
    {
        Invoice::markVoid($tenantId, $invoiceId);

        $chase = Chase::forInvoice($tenantId, $invoiceId);

        if ($chase !== null && !in_array($chase['status'], [Chase::STATUS_STOPPED, Chase::STATUS_COMPLETED], true)) {
            Chase::stop($tenantId, (int) $chase['id']);
        }
    }

    /**
     * A paid invoice must never send another reminder.
     */
    private function pauseChaseForPayment(int $tenantId, int $invoiceId): void
    {
        $chase = Chase::forInvoice($tenantId, $invoiceId);

        if ($chase === null) {
            return;
        }

        if (in_array($chase['status'], [Chase::STATUS_SCHEDULED, Chase::STATUS_ACTIVE], true)) {
            Chase::pause($tenantId, (int) $chase['id'], Chase::PAUSE_INVOICE_PAID);
        }
    }

    /**
     * @return array{status:string, client_id:?int, search:string, sort:string}
     */
    private function filters(Request $request): array
    {
        $status = strtolower(trim((string) $request->input('status', 'all')));
        $sort = (string) $request->input('sort', Invoice::DEFAULT_SORT);

        return [
            'status' => in_array($status, ['all', 'open', 'overdue', 'paid', 'void'], true) ? $status : 'all',
            'client_id' => ($clientId = (int) $request->input('client_id', 0)) > 0 ? $clientId : null,
            'search' => mb_substr(trim((string) $request->input('search', '')), 0, 100),
            // The model resolves an unknown key through its own allowlist; this
            // keeps an obviously bogus value out of the rendered filter UI too.
            'sort' => in_array($sort, Invoice::sortKeys(), true) ? $sort : Invoice::DEFAULT_SORT,
        ];
    }

    private function invoiceInput(Request $request): array
    {
        $rawAmount = trim((string) $request->input('amount', ''));
        $money = $rawAmount === '' ? null : MoneyParser::parse($rawAmount);

        $currency = strtoupper(trim((string) $request->input('currency', '')));

        if ($currency === '') {
            $currency = $money['currency'] ?? 'USD';
        }

        $status = strtolower(trim((string) $request->input('status', Invoice::STATUS_OPEN)));
        $paymentUrl = trim((string) $request->input('payment_url', ''));

        // The pay-button choice. Pasting a URL is itself the choice -- the form
        // does not make the user set both, so a link plus any override is read
        // as "use my link", which is what the resolution order does anyway.
        $linkMode = strtolower(trim((string) $request->input('payment_link_mode', '')));

        if ($paymentUrl !== '') {
            $linkMode = '';
        }

        return [
            'client_id' => (int) $request->input('client_id', 0),
            'number' => trim((string) $request->input('number', '')),
            'raw_amount' => $rawAmount,
            'amount_cents' => $money['cents'] ?? null,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD',
            'raw_due_date' => trim((string) $request->input('due_date', '')),
            'due_date' => DateParser::parseToDateString((string) $request->input('due_date', '')),
            'issue_date' => DateParser::parseToDateString((string) $request->input('issue_date', '')),
            'status' => in_array($status, [Invoice::STATUS_OPEN, Invoice::STATUS_PAID, Invoice::STATUS_VOID], true)
                ? $status
                : Invoice::STATUS_OPEN,
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
            // NULL rather than 'default': the column's whole point is that an
            // absent value means "follow the workspace", and storing the word
            // would make a later default change look like a per-invoice choice.
            'payment_link_mode' => in_array($linkMode, PaymentLinkService::INVOICE_MODES, true)
                && $linkMode !== PaymentLinkService::INVOICE_DEFAULT
                    ? $linkMode
                    : null,
            'notes' => trim((string) $request->input('notes', '')) ?: null,
        ];
    }

    /**
     * @return array<string, string> field => message
     */
    private function validate(int $tenantId, array $input, int $invoiceId): array
    {
        $errors = [];

        if ($input['number'] === '') {
            $errors['number'] = 'Enter an invoice number.';
        } elseif (mb_strlen($input['number']) > 64) {
            $errors['number'] = 'Invoice numbers are limited to 64 characters.';
        } else {
            // Unique per tenant, so surface the clash before the database does.
            $existing = Invoice::findByNumber($tenantId, $input['number']);

            if ($existing !== null && (int) $existing['id'] !== $invoiceId) {
                $errors['number'] = 'You already have an invoice numbered ' . $input['number'] . '.';
            }
        }

        if ($input['client_id'] <= 0) {
            $errors['client_id'] = 'Choose a client.';
        } elseif (!Client::exists($tenantId, $input['client_id'])) {
            $errors['client_id'] = 'That client does not exist.';
        }

        if ($input['raw_amount'] === '') {
            $errors['amount'] = 'Enter an amount.';
        } elseif ($input['amount_cents'] === null) {
            $errors['amount'] = 'Could not read "' . $input['raw_amount'] . '" as an amount.';
        } elseif ($input['amount_cents'] <= 0) {
            $errors['amount'] = 'The amount must be greater than zero.';
        }

        if ($input['raw_due_date'] === '') {
            $errors['due_date'] = 'Enter a due date.';
        } elseif ($input['due_date'] === null) {
            $errors['due_date'] = 'Could not read "' . $input['raw_due_date'] . '" as a date.';
        }

        if ($input['issue_date'] !== null && $input['due_date'] !== null && $input['issue_date'] > $input['due_date']) {
            $errors['issue_date'] = 'The issue date is after the due date.';
        }

        if ($input['payment_url'] !== null && !filter_var($input['payment_url'], FILTER_VALIDATE_URL)) {
            $errors['payment_url'] = 'That payment link is not a valid URL.';
        }

        return $errors;
    }

    /**
     * Shape a row for the client, with money and dates pre-rendered so the
     * browser never does currency arithmetic.
     */
    private function presentRow(array $row): array
    {
        if ($row === []) {
            return [];
        }

        $daysOverdue = isset($row['days_overdue'])
            ? (int) $row['days_overdue']
            : Invoice::daysOverdue($row);

        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'client_id' => (int) $row['client_id'],
            'client_name' => (string) ($row['client_name'] ?? ''),
            'client_email' => (string) ($row['client_email'] ?? ''),
            'client_company' => (string) ($row['client_company'] ?? ''),
            'amount_cents' => (int) $row['amount_cents'],
            'amount_formatted' => MoneyParser::format((int) $row['amount_cents'], (string) $row['currency']),
            'currency' => (string) $row['currency'],
            'due_date' => (string) $row['due_date'],
            'issue_date' => $row['issue_date'],
            'status' => (string) $row['status'],
            'days_overdue' => $daysOverdue,
            'is_overdue' => $row['status'] === Invoice::STATUS_OPEN && $daysOverdue > 0,
            'payment_url' => $row['payment_url'],
            'chase' => [
                'id' => isset($row['chase_id']) ? (int) $row['chase_id'] : null,
                'status' => $row['chase_status'] ?? null,
                'paused_reason' => $row['chase_paused_reason'] ?? null,
                'next_send_at' => $row['chase_next_send_at'] ?? null,
                'position' => isset($row['chase_position']) ? (int) $row['chase_position'] : null,
            ],
        ];
    }

    private function notFound(Request $request, string $message): never
    {
        if ($request->wantsJson()) {
            $this->json(['error' => $message], 404);
        }

        $this->redirect('/invoices?status=all&missing=1');
    }
}
