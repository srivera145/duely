<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Client;
use Keel\App\Models\Invoice;
use Keel\App\Services\MoneyParser;
use Keel\App\Services\TenantContext;
use Keel\App\Services\Timezones;
use Keel\Core\Activity;
use Keel\Core\Controller;
use Keel\Core\Request;

/**
 * Client list and editor.
 *
 * Clients are matched on email within a tenant, which is the same rule the CSV
 * importer uses — so adding a client by hand and importing one from a
 * spreadsheet converge on a single record rather than two.
 */
class ClientController extends Controller
{
    private const PER_PAGE = 100;

    /**
     * GET /clients
     */
    public function index(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $search = mb_substr(trim((string) $request->input('search', '')), 0, 100);

        $clients = $search !== ''
            ? Client::search($tenantId, $search, self::PER_PAGE)
            : Client::withOutstanding($tenantId, self::PER_PAGE);

        $workspaceTimezone = Timezones::forWorkspace($tenantId);

        $this->view('clients.index', [
            'title' => 'Clients - Duely',
            'metaDescription' => 'Everyone Duely sends invoice reminders to.',
            'clients' => array_map([$this, 'presentRow'], $clients),
            'search' => $search,
            'total' => Client::count($tenantId),
            'workspaceTimezone' => $workspaceTimezone,
            // Only worth flagging once the workspace has said it is somewhere
            // else. On a UTC workspace a UTC client is not a mismatch.
            'clientsOnDefault' => $workspaceTimezone === Timezones::DEFAULT
                ? 0
                : Client::countOnTimezone($tenantId, Timezones::DEFAULT),
            'notice' => $request->query['notice'] ?? null,
        ]);
    }

    /**
     * GET /clients/new
     */
    public function create(Request $request): void
    {
        $workspaceTimezone = Timezones::forWorkspace(TenantContext::requireId());

        $this->view('clients.edit', [
            'title' => 'New client - Duely',
            'client' => null,
            'invoices' => [],
            'timezones' => Timezones::catalogue(),
            'workspaceTimezone' => $workspaceTimezone,
        ]);
    }

    /**
     * POST /clients/timezone-backfill — move every UTC client onto the
     * workspace zone.
     *
     * Explicit, and only ever from a button the user pressed. Nothing here runs
     * on a schedule or during a migration: changing a client's timezone moves
     * every reminder scheduled for them by hours, and the person who knows which
     * clients are actually where is the user, not Duely.
     */
    public function backfillTimezones(Request $request): never
    {
        $tenantId = TenantContext::requireId();
        $workspace = Timezones::forWorkspace($tenantId);

        if ($workspace === Timezones::DEFAULT) {
            $this->redirect('/clients?notice=' . rawurlencode(
                'Set a workspace timezone first, on the timezone settings page.'
            ));
        }

        $moved = Client::retimezone($tenantId, Timezones::DEFAULT, $workspace);

        Activity::log('clients.timezone_backfilled', 'Organization', $tenantId, [
            'from' => Timezones::DEFAULT,
            'to' => $workspace,
            'clients' => $moved,
        ]);

        $this->redirect('/clients?notice=' . rawurlencode(
            $moved . ' ' . ($moved === 1 ? 'client' : 'clients') . ' moved to ' . $workspace . '.'
        ));
    }

    /**
     * GET /clients/{id}
     */
    public function edit(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $client = Client::find($tenantId, (int) $id);

        if ($client === null) {
            $this->notFound($request);
        }

        $this->view('clients.edit', [
            'title' => $client['name'] . ' - Duely',
            'client' => $client,
            'timezones' => Timezones::catalogue(),
            'workspaceTimezone' => Timezones::forWorkspace($tenantId),
            'invoices' => Invoice::forClient($tenantId, (int) $client['id'], 100),
        ]);
    }

    /**
     * POST /api/clients — create or update.
     */
    public function store(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $clientId = (int) $request->input('id', 0);

        $input = [
            'name' => trim((string) $request->input('name', '')),
            'email' => strtolower(trim((string) $request->input('email', ''))),
            'company' => trim((string) $request->input('company', '')) ?: null,
            'phone' => trim((string) $request->input('phone', '')) ?: null,
            'timezone' => trim((string) $request->input('timezone', ''))
                ?: Timezones::forWorkspace($tenantId),
            'notes' => trim((string) $request->input('notes', '')) ?: null,
        ];

        $errors = $this->validate($tenantId, $input, $clientId);

        if ($errors !== []) {
            $this->json(['errors' => $errors], 422);
        }

        if ($clientId > 0) {
            if (!Client::exists($tenantId, $clientId)) {
                $this->json(['errors' => ['email' => 'That client does not exist.']], 404);
            }

            Client::update($tenantId, $clientId, $input);
            Activity::log('client.updated', 'Client', $clientId, ['email' => $input['email']]);
        } else {
            // Match an existing client on email rather than inserting a
            // duplicate, exactly as the importer does.
            $existing = Client::findByEmail($tenantId, $input['email']);

            if ($existing !== null) {
                $clientId = (int) $existing['id'];
                Client::update($tenantId, $clientId, $input);

                $this->json([
                    'client' => $this->presentRow(Client::find($tenantId, $clientId) ?? []),
                    'id' => $clientId,
                    'matched_existing' => true,
                    'message' => 'You already had a client with that email, so we updated them instead of adding a second.',
                ]);
            }

            $clientId = Client::create($tenantId, $input);
            Activity::log('client.created', 'Client', $clientId, ['email' => $input['email']]);
        }

        $this->json([
            'client' => $this->presentRow(Client::find($tenantId, $clientId) ?? []),
            'id' => $clientId,
            'matched_existing' => false,
        ]);
    }

    /**
     * GET /api/clients — used by the invoice editor's client picker.
     */
    public function listJson(Request $request): void
    {
        $tenantId = TenantContext::requireId();
        $search = mb_substr(trim((string) $request->input('search', '')), 0, 100);

        $clients = $search !== ''
            ? Client::search($tenantId, $search, 25)
            : Client::active($tenantId, 100);

        $this->json(['clients' => array_map([$this, 'presentRow'], $clients)]);
    }

    /**
     * POST /api/clients/{id}/archive
     */
    public function archive(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $clientId = (int) $id;

        if (!Client::exists($tenantId, $clientId)) {
            $this->json(['error' => 'That client does not exist.'], 404);
        }

        $archive = filter_var($request->input('archived', true), FILTER_VALIDATE_BOOL);

        $archive
            ? Client::archive($tenantId, $clientId)
            : Client::unarchive($tenantId, $clientId);

        Activity::log('client.' . ($archive ? 'archived' : 'restored'), 'Client', $clientId);

        $this->json(['archived' => $archive]);
    }

    /**
     * POST /api/clients/{id}/delete
     */
    public function destroy(Request $request, string $id): void
    {
        $tenantId = TenantContext::requireId();
        $clientId = (int) $id;

        if (!Client::exists($tenantId, $clientId)) {
            $this->json(['error' => 'That client does not exist.'], 404);
        }

        // Deleting a client cascades to their invoices, so say so plainly
        // rather than letting the foreign key surprise someone.
        $invoiceCount = count(Invoice::forClient($tenantId, $clientId, 1000));

        if ($invoiceCount > 0 && !filter_var($request->input('confirm_cascade', false), FILTER_VALIDATE_BOOL)) {
            $this->json([
                'error' => 'This client has ' . $invoiceCount . ' invoice' . ($invoiceCount === 1 ? '' : 's')
                    . '. Deleting them removes those invoices and any chases too.',
                'requires_confirmation' => true,
                'invoice_count' => $invoiceCount,
            ], 409);
        }

        Client::delete($tenantId, $clientId);
        Activity::log('client.deleted', 'Client', $clientId, ['invoices_removed' => $invoiceCount]);

        $this->json(['deleted' => true, 'invoices_removed' => $invoiceCount]);
    }

    // -------------------------------------------------------------- internals

    /**
     * @return array<string, string>
     */
    private function validate(int $tenantId, array $input, int $clientId): array
    {
        $errors = [];

        if ($input['name'] === '') {
            $errors['name'] = 'Enter the client name.';
        } elseif (mb_strlen($input['name']) > 255) {
            $errors['name'] = 'That name is too long.';
        }

        if (!Timezones::isValid($input['timezone'])) {
            $errors['timezone'] = '"' . $input['timezone'] . '" is not a timezone Duely recognises.';
        }

        if ($input['email'] === '') {
            $errors['email'] = 'Enter the client email address.';
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '"' . $input['email'] . '" is not a valid email address.';
        } elseif ($clientId > 0) {
            // Editing must not collide with a different existing client.
            $existing = Client::findByEmail($tenantId, $input['email']);

            if ($existing !== null && (int) $existing['id'] !== $clientId) {
                $errors['email'] = 'Another client already uses that email address.';
            }
        }

        return $errors;
    }

    private function presentRow(array $row): array
    {
        if ($row === []) {
            return [];
        }

        $outstandingCents = (int) ($row['outstanding_cents'] ?? 0);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'company' => (string) ($row['company'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'timezone' => (string) ($row['timezone'] ?? 'UTC'),
            'notes' => (string) ($row['notes'] ?? ''),
            'is_archived' => (bool) ($row['is_archived'] ?? false),
            'open_invoice_count' => (int) ($row['open_invoice_count'] ?? 0),
            'outstanding_cents' => $outstandingCents,
            'outstanding_formatted' => MoneyParser::format($outstandingCents),
        ];
    }

    private function notFound(Request $request): never
    {
        if ($request->wantsJson()) {
            $this->json(['error' => 'That client does not exist.'], 404);
        }

        $this->redirect('/clients?missing=1');
    }
}
