<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Services\AiUsage;
use Keel\App\Services\InvoiceExtractor;
use Keel\App\Services\TenantContext;
use Keel\Core\Database;
use Tests\Support\FakeAiService;
use Tests\TestCase;

/**
 * Reading an invoice document.
 *
 * The load-bearing claims, each with a section:
 *
 *   Nothing is sent until a workspace opts in. The writing assistant scrubs
 *   real values before they leave; this feature cannot, so consent is the
 *   control that replaces scrubbing.
 *
 *   Nothing is written. The endpoint returns a draft. An invoice appears only
 *   when the user saves the form, through the same validation as any other.
 *
 *   A field Claude got wrong must surface as a warning rather than sail into
 *   the form, because a wrong due date sends a client a reminder on the wrong
 *   day and a wrong amount sends them a number nobody agreed to.
 */
class InvoiceExtractionFeatureTest extends TestCase
{
    private array $user;
    private int $tenantId;
    private DateTimeImmutable $now;
    private FakeAiService $ai;
    private InvoiceExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['ANTHROPIC_API_KEY'] = 'sk-test-not-a-real-key';
        $_SERVER['ANTHROPIC_API_KEY'] = 'sk-test-not-a-real-key';

        $this->user = $this->createUser(['email' => 'reader@studio.test', 'name' => 'Reader']);
        $this->tenantId = TenantContext::forUser((int) $this->user['id']);
        $this->now = new DateTimeImmutable('2026-08-26 10:00:00', new DateTimeZone('UTC'));

        $this->ai = new FakeAiService();
        $this->extractor = new InvoiceExtractor($this->ai, new AiUsage());
    }

    protected function tearDown(): void
    {
        unset($_ENV['ANTHROPIC_API_KEY'], $_SERVER['ANTHROPIC_API_KEY']);

        parent::tearDown();
    }

    // ------------------------------------------------------- consent first

    public function testNothingIsSentUntilTheWorkspaceOptsIn(): void
    {
        self::assertFalse(
            InvoiceExtractor::isEnabledFor($this->tenantId),
            'a new workspace must start with this switched off'
        );

        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('switched off', (string) $result['error']);
        self::assertSame(0, $this->ai->callCount(), 'nothing may reach the API before consent');
    }

    public function testConsentIsRecordedWithWhoAndWhen(): void
    {
        $this->extractor->setEnabled($this->tenantId, (int) $this->user['id'], true, $this->now);

        $row = $this->organization();

        self::assertSame(1, (int) $row['ai_extraction_enabled']);
        self::assertNotNull($row['ai_extraction_consented_at']);
        self::assertSame((int) $this->user['id'], (int) $row['ai_extraction_consented_by']);

        // Switching it off stops the feature but keeps the record that it was
        // once agreed to.
        $this->extractor->setEnabled($this->tenantId, (int) $this->user['id'], false, $this->now);
        $off = $this->organization();

        self::assertSame(0, (int) $off['ai_extraction_enabled']);
        self::assertNotNull($off['ai_extraction_consented_at'], 'the history survives being turned off');
    }

    // --------------------------------------------------------- the reading

    public function testAReadableInvoiceBecomesADraftAndNothingIsSaved(): void
    {
        $this->enable();
        $this->ai->replyWithJson([
            'invoice_number' => 'INV-2041',
            'client_name' => 'Northwind Studio',
            'client_email' => 'ellie@northwind.test',
            'amount' => '2400.00',
            'currency' => 'USD',
            'issue_date' => '2026-07-14',
            'due_date' => '2026-08-06',
            'confidence' => 'high',
            'notes' => null,
        ]);

        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertSame('INV-2041', $result['draft']['number']);
        self::assertSame('Northwind Studio', $result['draft']['client_name']);
        self::assertSame('ellie@northwind.test', $result['draft']['client_email']);
        self::assertSame('2400.00', $result['draft']['amount']);
        self::assertSame('USD', $result['draft']['currency']);
        self::assertSame('2026-08-06', $result['draft']['due_date']);
        self::assertSame('high', $result['confidence']);
        self::assertSame([], $result['warnings']);

        // The whole point: reading is not saving.
        self::assertSame(
            0,
            (int) Database::connection()
                ->query('SELECT COUNT(*) FROM invoices WHERE tenant_id = ' . $this->tenantId)
                ->fetchColumn(),
            'extraction must never write an invoice'
        );
    }

    public function testTheRequestIsSchemaConstrainedRatherThanPromptConstrained(): void
    {
        $this->enable();
        $this->ai->replyWithJson($this->goodPayload());

        $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        $schema = $this->ai->lastSchema();

        self::assertIsArray($schema, 'the call must carry a JSON Schema');
        self::assertFalse($schema['additionalProperties'], 'an invented field must be impossible');
        self::assertContains('due_date', $schema['required']);
        self::assertSame(['high', 'medium', 'low'], $schema['properties']['confidence']['enum']);

        // Every value field is nullable: an absent field must come back empty
        // rather than as something plausible.
        foreach (['invoice_number', 'client_name', 'client_email', 'amount', 'due_date'] as $field) {
            self::assertContains('null', $schema['properties'][$field]['type'], $field . ' must allow null');
        }
    }

    // ------------------------------------------- what a bad reading must do

    public function testAnImpossibleDateIsRefusedRatherThanPassedToTheForm(): void
    {
        $this->enable();
        $this->ai->replyWithJson($this->goodPayload([
            'due_date' => '2026-02-31',   // there is no such day
            'confidence' => 'medium',
        ]));

        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertTrue($result['ok']);
        self::assertSame('', $result['draft']['due_date'], 'a date that does not exist must not reach the form');
        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('due date', implode(' ', $result['warnings']));
    }

    public function testAnAmountThatIsNotANumberIsRefused(): void
    {
        $this->enable();
        $this->ai->replyWithJson($this->goodPayload(['amount' => '$2,400.00']));

        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertSame('', $result['draft']['amount'], 'a formatted amount is not a number');
        self::assertStringContainsString('amount', implode(' ', $result['warnings']));
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        $this->enable();
        $this->ai->replyWithJson($this->goodPayload(['client_email' => 'not-an-address']));

        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertSame('', $result['draft']['client_email']);
        self::assertStringContainsString('email', implode(' ', $result['warnings']));
    }

    public function testAMissingDueDateIsCalledOutBecauseRemindersAreTimedFromIt(): void
    {
        $this->enable();
        $this->ai->replyWithJson($this->goodPayload(['due_date' => null, 'confidence' => 'low']));

        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertSame('', $result['draft']['due_date']);
        self::assertStringContainsString('No due date', implode(' ', $result['warnings']));
    }

    public function testAFailedCallDegradesToASentenceRatherThanAnException(): void
    {
        $this->enable();
        $this->ai->failWith('upstream exploded');

        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('by hand', (string) $result['error']);
    }

    // --------------------------------------------------------- the budget

    public function testTheDailyBudgetIsSharedWithTheWritingAssistant(): void
    {
        $this->enable();

        // Spend the whole allowance on rewrites.
        $usage = new AiUsage();
        for ($i = 0; $i < AiUsage::DAILY_LIMIT; $i++) {
            $usage->record($this->tenantId, 'rewrite', 'claude-opus-5', [], 'accepted', null, microtime(true), $this->now);
        }

        $this->ai->replyWithJson($this->goodPayload());
        $result = $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);

        self::assertFalse($result['ok'], 'twenty a day means twenty, not twenty each');
        self::assertStringContainsString('AI calls for today', (string) $result['error']);
        self::assertSame(0, $this->ai->callCount());
    }

    public function testAReadingIsChargedToTheBudget(): void
    {
        $this->enable();
        $this->ai->replyWithJson($this->goodPayload());

        $before = (new AiUsage())->allowance($this->tenantId, $this->now)['used'];
        $this->extractor->extract($this->tenantId, $this->pdf(), 'invoice.pdf', $this->now);
        $after = (new AiUsage())->allowance($this->tenantId, $this->now)['used'];

        self::assertSame($before + 1, $after);

        $row = Database::connection()
            ->query('SELECT action, outcome FROM ai_usage WHERE tenant_id = ' . $this->tenantId . ' ORDER BY id DESC LIMIT 1')
            ->fetch();

        self::assertSame('invoice_extract', $row['action']);
        self::assertSame('accepted', $row['outcome']);
    }

    // ------------------------------------------------------- what it accepts

    public function testAFileThatIsNotADocumentIsRefusedBeforeAnythingIsSent(): void
    {
        $this->enable();

        $path = tempnam(sys_get_temp_dir(), 'duely') . '.txt';
        file_put_contents($path, "just some text\n");

        $result = $this->extractor->extract($this->tenantId, $path, 'notes.txt', $this->now);
        unlink($path);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('PDF or a photo', (string) $result['error']);
        self::assertSame(0, $this->ai->callCount(), 'an unsupported file must not cost a call');
    }

    // -------------------------------------------------------------- endpoint

    public function testTheEndpointRequiresConsentAndCsrf(): void
    {
        $this->signIn();

        self::assertSame(419, $this->postJson('/api/invoices/extract', [])->status, 'CSRF is required');

        $status = $this->getJson('/api/invoices/extraction/status');

        self::assertSame(200, $status->status);
        self::assertFalse($status->json()['enabled'], 'off until somebody says otherwise');
    }

    public function testConsentCanBeGivenAndWithdrawnThroughTheEndpoint(): void
    {
        $this->signIn();

        $on = $this->postJson('/api/invoices/extraction/consent', [
            '_csrf' => $this->csrfToken(),
            'enabled' => true,
        ]);

        self::assertSame(200, $on->status);
        self::assertTrue($on->json()['enabled']);
        self::assertTrue(InvoiceExtractor::isEnabledFor($this->tenantId));

        $off = $this->postJson('/api/invoices/extraction/consent', [
            '_csrf' => $this->csrfToken(),
            'enabled' => false,
        ]);

        self::assertFalse($off->json()['enabled']);
        self::assertFalse(InvoiceExtractor::isEnabledFor($this->tenantId));
    }

    // -------------------------------------------------------------- internals

    private function enable(): void
    {
        $this->extractor->setEnabled($this->tenantId, (int) $this->user['id'], true, $this->now);
    }

    private function signIn(): void
    {
        \Keel\Core\Session::put('user_id', (int) $this->user['id']);
        \Keel\Core\Session::put('user_email', (string) $this->user['email']);
        \Keel\Core\Session::put('organization_id', $this->tenantId);
        \Keel\Core\Auth::setUserId(null);
    }

    private function organization(): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
        $statement->execute([$this->tenantId]);

        return $statement->fetch() ?: [];
    }

    private function goodPayload(array $overrides = []): array
    {
        return array_merge([
            'invoice_number' => 'INV-2041',
            'client_name' => 'Northwind Studio',
            'client_email' => 'ellie@northwind.test',
            'amount' => '2400.00',
            'currency' => 'USD',
            'issue_date' => '2026-07-14',
            'due_date' => '2026-08-06',
            'confidence' => 'high',
            'notes' => null,
        ], $overrides);
    }

    /**
     * The smallest thing `finfo` will call application/pdf.
     */
    private function pdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'duely') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        return $path;
    }
}
