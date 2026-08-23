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
