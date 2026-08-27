<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Client;
use Keel\App\Models\Invoice;
use Keel\Core\Database;
use Tests\TestCase;

/**
 * The client, invoice, and import routes driven through the real router, so
 * auth, CSRF and tenant resolution are all part of what is under test.
 *
 * The import assertions here exist mainly to prove the one thing users worry
 * about: uploading a file does not import it.
 */
class InvoiceRoutesFeatureTest extends TestCase
{
    protected function tearDown(): void
    {
        // Staged uploads are real files; do not let a test run accumulate them.
        $root = self::$basePath . '/storage/app/imports';

        if (is_dir($root)) {
            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                array_map('unlink', glob($directory . '/*') ?: []);
                @rmdir($directory);
            }
            @rmdir($root);
        }

        parent::tearDown();
    }

    public function testAbandonedUploadsAreSweptAcrossEveryTenant(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $upload = json_decode($this->uploadCsv($this->sampleCsv())->body, true);

        $staged = glob(self::$basePath . '/storage/app/imports/*/*.csv') ?: [];
        self::assertNotEmpty($staged, 'the upload was not staged');

        // Age the staged file past the retention window.
        touch($staged[0], time() - (7 * 3600));

        self::assertGreaterThan(0, \Keel\App\Services\ImportStaging::sweep());
        self::assertFileDoesNotExist($staged[0]);
    }

    public function testEveryDuelyPageRequiresAuthentication(): void
    {
        foreach (['/invoices', '/invoices/new', '/invoices/import', '/clients', '/clients/new'] as $path) {
            $response = $this->get($path);

            self::assertSame(302, $response->status, $path . ' was reachable without a session');
            self::assertSame('/login', $response->header('Location'));
        }
    }

    public function testWriteEndpointsRequireACsrfToken(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        foreach (['/api/invoices', '/api/clients', '/api/invoices/import/commit'] as $path) {
            $response = $this->post($path, ['number' => 'INV-1']);

            self::assertSame(419, $response->status, $path . ' is not CSRF protected');
        }
    }

    public function testTheInvoiceListRendersWithFiltersAndChaseStatus(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);

        $clientId = Client::create($tenantId, ['name' => 'Bill Payer', 'email' => 'bill@bigco.test']);
        Invoice::create($tenantId, [
            'client_id' => $clientId,
            'number' => 'INV-1001',
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => date('Y-m-d', strtotime('-12 days')),
        ]);

        $response = $this->get('/invoices');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('INV-1001', $response->body);
        self::assertStringContainsString('Bill Payer', $response->body);
        self::assertStringContainsString('$3,200.00', $response->body);
        self::assertStringContainsString('12 days', $response->body, 'days overdue is not shown');
        self::assertStringContainsString('Not chasing', $response->body, 'chase status is not shown inline');
    }

    public function testTheStatusFilterNarrowsTheRenderedList(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);
        $clientId = Client::create($tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        Invoice::create($tenantId, ['client_id' => $clientId, 'number' => 'OPEN-1', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => '2026-01-01']);
        Invoice::create($tenantId, ['client_id' => $clientId, 'number' => 'PAID-1', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => '2026-01-01', 'status' => 'paid']);

        $open = $this->get('/invoices?status=open');
        self::assertStringContainsString('OPEN-1', $open->body);
        self::assertStringNotContainsString('PAID-1', $open->body);

        $paid = $this->get('/invoices?status=paid');
        self::assertStringContainsString('PAID-1', $paid->body);
        self::assertStringNotContainsString('OPEN-1', $paid->body);
    }

    public function testCreatingAnInvoiceThroughTheApi(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);
        $clientId = Client::create($tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        $response = $this->postJson('/api/invoices', [
            '_csrf' => $this->csrfToken(),
            'client_id' => $clientId,
            'number' => 'INV-2001',
            // Deliberately messy, to prove the editor is as forgiving as import.
            'amount' => '$3,200.00',
            'due_date' => '08/31/2026',
        ]);

        self::assertSame(200, $response->status, $response->body);

        $invoice = Invoice::findByNumber($tenantId, 'INV-2001');
        self::assertSame(320000, (int) $invoice['amount_cents']);
        self::assertSame('2026-08-31', $invoice['due_date']);
    }

    public function testAnUnreadableAmountComesBackAsAFieldError(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);
        $clientId = Client::create($tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        $response = $this->postJson('/api/invoices', [
            '_csrf' => $this->csrfToken(),
            'client_id' => $clientId,
            'number' => 'INV-2002',
            'amount' => 'to be confirmed',
            'due_date' => '2026-08-31',
        ]);

        self::assertSame(422, $response->status);

        $errors = json_decode($response->body, true)['errors'];
        self::assertArrayHasKey('amount', $errors);
        self::assertStringContainsString('to be confirmed', $errors['amount']);
        self::assertNull(Invoice::findByNumber($tenantId, 'INV-2002'), 'a rejected invoice was still saved');
    }

    public function testADuplicateInvoiceNumberIsRejectedWithAClearMessage(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);
        $clientId = Client::create($tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        Invoice::create($tenantId, [
            'client_id' => $clientId, 'number' => 'INV-3001', 'amount_cents' => 100,
            'currency' => 'USD', 'due_date' => '2026-08-01',
        ]);

        $response = $this->postJson('/api/invoices', [
            '_csrf' => $this->csrfToken(),
            'client_id' => $clientId,
            'number' => 'INV-3001',
            'amount' => '200',
            'due_date' => '2026-09-01',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('already have an invoice', json_decode($response->body, true)['errors']['number']);
    }

    public function testCreatingAClientMatchesAnExistingEmailInsteadOfDuplicating(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);

        Client::create($tenantId, ['name' => 'Bill Payer', 'email' => 'bill@bigco.test']);

        $response = $this->postJson('/api/clients', [
            '_csrf' => $this->csrfToken(),
            'name' => 'William Payer',
            'email' => 'BILL@bigco.test',
        ]);

        self::assertSame(200, $response->status, $response->body);

        $body = json_decode($response->body, true);
        self::assertTrue($body['matched_existing'], 'a second client was created for the same email');
        self::assertSame(1, Client::count($tenantId));
        self::assertNotEmpty($body['message']);
    }

    // ------------------------------------------------- the import wizard

    public function testUploadingAFileDoesNotImportAnything(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);

        $response = $this->uploadCsv($this->sampleCsv());

        self::assertSame(200, $response->status, $response->body);

        $body = json_decode($response->body, true);
        self::assertFalse($body['committed'], 'upload reported a commit');
        self::assertNotEmpty($body['token']);
        self::assertSame(3, $body['total_rows']);
        self::assertSame(0, Invoice::count($tenantId), 'uploading imported invoices');
    }

    public function testValidatingDoesNotImportAnythingEither(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);

        $upload = json_decode($this->uploadCsv($this->sampleCsv())->body, true);

        $response = $this->postJson('/api/invoices/import/validate', [
            '_csrf' => $this->csrfToken(),
            'token' => $upload['token'],
            'mapping' => $upload['mapping'],
        ]);

        self::assertSame(200, $response->status, $response->body);

        $body = json_decode($response->body, true);
        self::assertFalse($body['committed']);
        self::assertSame(2, $body['summary']['valid']);
        self::assertSame(1, $body['summary']['invalid']);
        self::assertSame(0, Invoice::count($tenantId), 'validating imported invoices');
    }

    public function testCommitRefusesWithoutAnExplicitConfirmation(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);

        $upload = json_decode($this->uploadCsv($this->sampleCsv())->body, true);

        $response = $this->postJson('/api/invoices/import/commit', [
            '_csrf' => $this->csrfToken(),
            'token' => $upload['token'],
            'mapping' => $upload['mapping'],
            // `confirmed` deliberately absent.
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Confirm', json_decode($response->body, true)['error']);
        self::assertSame(0, Invoice::count($tenantId));
    }

    public function testCommitImportsOnceConfirmed(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);

        $upload = json_decode($this->uploadCsv($this->sampleCsv())->body, true);

        $response = $this->postJson('/api/invoices/import/commit', [
            '_csrf' => $this->csrfToken(),
            'token' => $upload['token'],
            'mapping' => $upload['mapping'],
            'confirmed' => true,
        ]);

        self::assertSame(200, $response->status, $response->body);

        $body = json_decode($response->body, true);
        self::assertTrue($body['committed']);
        self::assertSame(2, $body['imported']);
        self::assertCount(1, $body['errors']);
        self::assertSame(2, Invoice::count($tenantId));
    }

    public function testAMappingMissingARequiredColumnIsRefused(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $tenantId = $this->tenantIdFor((int) $user['id']);

        $upload = json_decode($this->uploadCsv($this->sampleCsv())->body, true);

        $mapping = $upload['mapping'];
        $mapping['amount'] = null;

        $response = $this->postJson('/api/invoices/import/commit', [
            '_csrf' => $this->csrfToken(),
            'token' => $upload['token'],
            'mapping' => $mapping,
            'confirmed' => true,
        ]);

        self::assertSame(422, $response->status);
        self::assertContains('Amount', json_decode($response->body, true)['missing_required']);
        self::assertSame(0, Invoice::count($tenantId));
    }

    public function testAnotherTenantsStagedUploadCannotBeCommitted(): void
    {
        // Tenant A stages a file.
        $userA = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada']);
        $upload = json_decode($this->uploadCsv($this->sampleCsv())->body, true);
        $tenantA = $this->tenantIdFor((int) $userA['id']);

        // Tenant B tries to commit it with the stolen token.
        $userB = $this->actingAs(['email' => 'mallory@rival.test', 'name' => 'Mallory']);
        $tenantB = $this->tenantIdFor((int) $userB['id']);
        self::assertNotSame($tenantA, $tenantB);

        $response = $this->postJson('/api/invoices/import/commit', [
            '_csrf' => $this->csrfToken(),
            'token' => $upload['token'],
            'mapping' => $upload['mapping'],
            'confirmed' => true,
        ]);

        self::assertSame(422, $response->status, 'a token from another tenant resolved to a file');
        self::assertSame(0, Invoice::count($tenantB));
        self::assertSame(0, Invoice::count($tenantA));
    }

    // -------------------------------------------------------------- helpers

    // ------------------- self-check: the invoice is on the invoice's own URL

    public function testTheCanonicalInvoiceUrlIsTheInvoiceNotTheForm(): void
    {
        $tenantId = $this->signedInTenant();
        $invoiceId = $this->routeInvoice($tenantId, 'INV-ROUTE-1');

        $body = $this->get('/invoices/' . $invoiceId)->body;

        // The controls that only live on this page. Reaching them used to
        // require typing /timeline onto the address bar, which meant a user who
        // imported invoices had no way to start chasing them.
        self::assertStringContainsString('Start chasing', $body);
        self::assertStringContainsString('data-mark-paid', $body);

        // And it is not the form.
        self::assertStringNotContainsString('id="invoice-form"', $body);
    }

    public function testTheEditFormMovedToItsOwnSubPath(): void
    {
        $tenantId = $this->signedInTenant();
        $invoiceId = $this->routeInvoice($tenantId, 'INV-ROUTE-2');

        $response = $this->get('/invoices/' . $invoiceId . '/edit');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="invoice-form"', $response->body);
    }

    public function testTheOldTimelineUrlRedirectsPermanently(): void
    {
        $tenantId = $this->signedInTenant();
        $invoiceId = $this->routeInvoice($tenantId, 'INV-ROUTE-3');

        $response = $this->get('/invoices/' . $invoiceId . '/timeline');

        // 301, not 302: the old URL is in browser history and may be
        // bookmarked, and permanent is what tells the browser to stop asking.
        self::assertSame(301, $response->status);
        self::assertSame('/invoices/' . $invoiceId, $response->header('Location'));
    }

    public function testTheInvoiceListLinksToTheInvoice(): void
    {
        $tenantId = $this->signedInTenant();
        $invoiceId = $this->routeInvoice($tenantId, 'INV-ROUTE-4');

        $body = $this->get('/invoices')->body;

        self::assertStringContainsString('href="/invoices/' . $invoiceId . '"', $body);
    }

    public function testTheInvoicePageOffersAWayToEditAndTheFormAWayBack(): void
    {
        $tenantId = $this->signedInTenant();
        $invoiceId = $this->routeInvoice($tenantId, 'INV-ROUTE-5');

        // A round trip: from the invoice to the form and back, both by link.
        self::assertStringContainsString(
            'href="/invoices/' . $invoiceId . '/edit"',
            $this->get('/invoices/' . $invoiceId)->body,
            'The invoice has no Edit link.'
        );

        self::assertStringContainsString(
            'href="/invoices/' . $invoiceId . '"',
            $this->get('/invoices/' . $invoiceId . '/edit')->body,
            'The form has no way back to the invoice.'
        );
    }

    public function testNewInvoiceStillGoesBackToTheList(): void
    {
        $this->signedInTenant();

        // There is no invoice to go back to yet, so the list is the right
        // destination here and only here.
        $body = $this->get('/invoices/new')->body;

        self::assertStringContainsString('href="/invoices"', $body);
    }

    public function testNoInternalLinkStillTreatsTheCanonicalUrlAsTheForm(): void
    {
        // Searched rather than remembered. A link that relies on the
        // compatibility redirect is a link that is quietly wrong, and this is
        // the sweep that catches the one somebody forgets.
        $offenders = [];

        foreach ([
            'views/dashboard', 'views/invoices', 'views/clients', 'views/partials',
        ] as $directory) {
            foreach (glob(dirname(__DIR__, 2) . '/' . $directory . '/*.php') ?: [] as $file) {
                foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                    if (str_contains($line, '/invoices/') && str_contains($line, '/timeline')) {
                        $offenders[] = basename($file) . ':' . ($number + 1);
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Still linking through the redirect: ' . implode(', ', $offenders));
    }

    /**
     * Sign in and resolve the workspace, the way the other tests here do.
     */
    private function signedInTenant(): int
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        return $this->tenantIdFor((int) $user['id']);
    }

    private function routeInvoice(int $tenantId, string $number): int
    {
        $clientId = Client::findOrCreate($tenantId, 'dana@client.test', [
            'name' => 'Dana Whitfield',
            'company' => 'Whitfield & Partners',
            'timezone' => 'UTC',
        ]);

        return Invoice::create($tenantId, [
            'client_id' => $clientId,
            'number' => $number,
            'amount_cents' => 320000,
            'currency' => 'USD',
            'due_date' => date('Y-m-d', strtotime('-18 days')),
        ]);
    }

    private function tenantIdFor(int $userId): int
    {
        $statement = Database::connection()->prepare('SELECT organization_id FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $organizationId = $statement->fetchColumn();

        // A solo user gets a workspace on first use; resolve it the same way.
        return $organizationId ? (int) $organizationId : \Keel\App\Services\TenantContext::forUser($userId);
    }

    /**
     * Two good rows and one with an unreadable amount.
     */
    private function sampleCsv(): string
    {
        return "Invoice #,Client Name,Customer Email,Amount Due,Due Date\n"
            . "INV-1,Bill Payer,bill@bigco.test,\"\$1,200.00\",2026-08-01\n"
            . "INV-2,Sam Chen,sam@chen.test,850.50,08/15/2026\n"
            . "INV-3,Broken Row,broken@bigco.test,to be confirmed,2026-09-01\n";
    }

    /**
     * Stage a CSV through the real upload endpoint via a temp file in $_FILES.
     */
    private function uploadCsv(string $csv): \Tests\Support\TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'duely-test-') . '.csv';
        file_put_contents($path, $csv);

        $_FILES['file'] = [
            'name' => 'invoices.csv',
            'type' => 'text/csv',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($csv),
        ];

        try {
            return $this->post('/api/invoices/import/upload', ['_csrf' => $this->csrfToken()]);
        } finally {
            unset($_FILES['file']);
            @unlink($path);
        }
    }
}
