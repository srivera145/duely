<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Client;
use Keel\App\Models\Invoice;
use Keel\App\Services\ColumnMapper;
use Keel\App\Services\CsvImporter;
use Keel\App\Services\DateParser;
use Keel\App\Services\MoneyParser;
use Keel\Core\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The importer's promises: forgiving parsing, per-row error reporting, nothing
 * written before confirmation, idempotent re-import, and strict tenant scoping.
 */
class CsvImportFeatureTest extends TestCase
{
    private int $tenantId;
    private int $otherTenantId;
    private CsvImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (int) $this->createOrganization('Acme Design')['id'];
        $this->otherTenantId = (int) $this->createOrganization('Rival Studio')['id'];
        $this->importer = new CsvImporter();
    }

    // ------------------------------------------------------------ parsers

    #[DataProvider('moneyProvider')]
    public function testMoneyParsesPermissivelyToExactCents(string $input, ?int $expected): void
    {
        self::assertSame($expected, MoneyParser::parse($input)['cents'] ?? null, $input);
    }

    public static function moneyProvider(): array
    {
        return [
            'dollar with commas' => ['$3,200.00', 320000],
            'bare integer' => ['3200', 320000],
            'comma thousands' => ['3,200', 320000],
            'sub-dollar' => ['$0.07', 7],
            'european' => ['1.234,56', 123456],
            'us decimal' => ['1,234.56', 123456],
            'lone dot thousands' => ['1.234', 123400],
            'comma decimal' => ['1,50', 150],
            'accounting negative' => ['(1,200.00)', -120000],
            'iso prefix' => ['USD 1,200', 120000],
            'pound' => ['£99.99', 9999],
            'euro european' => ['€1.500,00', 150000],
            'space thousands' => ['1 200,50', 120050],
            'padded' => ['  42  ', 4200],
            'millions' => ['1,234,567.89', 123456789],
            'not money' => ['to be confirmed', null],
            'empty' => ['', null],
            'three decimals' => ['12.3456', null],
        ];
    }

    #[DataProvider('dateProvider')]
    public function testDatesParsePermissively(string $input, string $locale, ?string $expected): void
    {
        self::assertSame($expected, DateParser::parseToDateString($input, $locale), $input);
    }

    public static function dateProvider(): array
    {
        return [
            'iso' => ['2026-08-05', 'auto', '2026-08-05'],
            'us slashes' => ['08/05/2026', 'mdy', '2026-08-05'],
            'uk slashes' => ['05/08/2026', 'dmy', '2026-08-05'],
            'day over 12 decides itself' => ['13/08/2026', 'auto', '2026-08-13'],
            'month first over 12' => ['08/13/2026', 'auto', '2026-08-13'],
            'textual month first' => ['Aug 5, 2026', 'auto', '2026-08-05'],
            'textual day first' => ['5 August 2026', 'auto', '2026-08-05'],
            'two digit year' => ['5-Aug-26', 'auto', '2026-08-05'],
            'dotted' => ['08.05.2026', 'mdy', '2026-08-05'],
            'spreadsheet serial' => ['46239', 'auto', '2026-08-05'],
            'nonsense' => ['not a date', 'auto', null],
            'impossible' => ['99/99/2026', 'auto', null],
        ];
    }

    public function testTheLocaleToggleChangesAnAmbiguousDate(): void
    {
        self::assertSame('2026-03-04', DateParser::parseToDateString('03/04/2026', DateParser::LOCALE_MDY));
        self::assertSame('2026-04-03', DateParser::parseToDateString('03/04/2026', DateParser::LOCALE_DMY));
        self::assertTrue(DateParser::isAmbiguous('03/04/2026'));
        self::assertFalse(DateParser::isAmbiguous('13/04/2026'), 'a 13 cannot be a month');
    }

    public function testCommonHeaderNamesAreAutodetected(): void
    {
        $mapping = ColumnMapper::detect(
            ['Invoice #', 'Client Name', 'Customer Email', 'Company', 'Amount Due', 'Due Date', 'Status']
        );

        self::assertSame(0, $mapping['number']);
        self::assertSame(1, $mapping['client_name']);
        self::assertSame(2, $mapping['client_email']);
        self::assertSame(4, $mapping['amount']);
        self::assertSame(5, $mapping['due_date']);
        self::assertSame([], ColumnMapper::missingRequired($mapping));
    }

    public function testInvoiceTotalMapsToAmountNotToInvoiceNumber(): void
    {
        $mapping = ColumnMapper::detect(['Invoice', 'Customer', 'Invoice Total', 'Date Due', 'Email Address']);

        self::assertSame(2, $mapping['amount']);
        self::assertSame(0, $mapping['number']);
    }

    // ------------------------------------------------- the wizard's stages

    public function testPreviewShowsTenRowsAndWritesNothing(): void
    {
        $csv = $this->messyCsv();
        $preview = $this->importer->preview($csv);

        self::assertCount(CsvImporter::PREVIEW_ROWS, $preview['rows']);
        self::assertSame(50, $preview['total_rows']);
        self::assertTrue($preview['has_header_row']);
        self::assertSame([], ColumnMapper::missingRequired($preview['mapping']));
        self::assertSame(0, Invoice::count($this->tenantId), 'preview must never write');
    }

    public function testValidateReportsWithoutWriting(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];

        $result = $this->importer->validate($this->tenantId, $csv, $mapping);

        self::assertSame(48, $result['summary']['valid']);
        self::assertSame(2, $result['summary']['invalid']);
        self::assertSame(0, Invoice::count($this->tenantId), 'validate must never write');
    }

    // ----------------------------------------------- self-check: 48 and 2

    public function testAMessyFileImportsFortyEightRowsAndReportsTwo(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];

        $result = $this->importer->commit($this->tenantId, $csv, $mapping);

        self::assertSame(48, $result['imported']);
        self::assertSame(48, $result['created']);
        self::assertCount(2, $result['errors']);
        self::assertSame(48, Invoice::count($this->tenantId));

        // Each rejection carries a line number and a reason a person can act on.
        foreach ($result['errors'] as $error) {
            self::assertGreaterThan(0, $error['line']);
            self::assertNotEmpty($error['reason']);
            self::assertStringNotContainsString('Exception', $error['reason']);
        }

        self::assertStringContainsString('to be confirmed', $result['errors'][0]['reason']);
        self::assertStringContainsString('not a valid email', $result['errors'][1]['reason']);
    }

    public function testTheMessyFormatsActuallyParsedCorrectly(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];
        $this->importer->commit($this->tenantId, $csv, $mapping);

        // "$1,250.00" — currency symbol plus a thousands comma.
        $symbol = Invoice::findByNumber($this->tenantId, 'INV-1005');
        self::assertSame(125000, (int) $symbol['amount_cents']);
        self::assertSame('USD', $symbol['currency']);
        self::assertSame('2026-06-04', $symbol['due_date']);

        // "Jun 7, 2026" — a textual month.
        self::assertSame('2026-06-07', Invoice::findByNumber($this->tenantId, 'INV-1002')['due_date']);

        // "10 Jun 2026" — day-first textual, and "USD 744.00" with an ISO code.
        $iso = Invoice::findByNumber($this->tenantId, 'INV-1003');
        self::assertSame('2026-06-10', $iso['due_date']);
        self::assertSame(74400, (int) $iso['amount_cents']);

        // "3,978" — a bare thousands comma is not a decimal point.
        self::assertSame(397800, (int) Invoice::findByNumber($this->tenantId, 'INV-1004')['amount_cents']);

        // A quoted field containing a comma survived the CSV parse.
        $quoted = Invoice::findByNumber($this->tenantId, 'INV-1004');
        self::assertStringContainsString('balance to follow', (string) $quoted['notes']);

        // "Unpaid" is an open invoice, "Paid" is not.
        self::assertSame('open', $iso['status']);
        self::assertSame('paid', $symbol['status']);
    }

    public function testOneBadRowNeverCostsTheOthers(): void
    {
        $csv = "Invoice #,Email,Amount,Due Date\n"
            . "INV-A,a@test.test,not-money,2026-08-01\n"
            . "INV-B,bad-email,100,2026-08-01\n"
            . "INV-C,c@test.test,50.00,never\n"
            . "INV-D,d@test.test,75.00,2026-08-01\n";

        $result = $this->importer->commit(
            $this->tenantId,
            $csv,
            ColumnMapper::detect(['Invoice #', 'Email', 'Amount', 'Due Date'])
        );

        self::assertSame(1, $result['imported']);
        self::assertCount(3, $result['errors']);
        self::assertNotNull(Invoice::findByNumber($this->tenantId, 'INV-D'));
    }

    // --------------------------------------- self-check: idempotent re-import

    public function testReimportingTheSameFileCreatesNoDuplicates(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];

        $this->importer->commit($this->tenantId, $csv, $mapping);
        $invoicesAfterFirst = Invoice::count($this->tenantId);
        $clientsAfterFirst = Client::count($this->tenantId);

        $second = $this->importer->commit($this->tenantId, $csv, $mapping);

        self::assertSame($invoicesAfterFirst, Invoice::count($this->tenantId), 'the re-import duplicated invoices');
        self::assertSame($clientsAfterFirst, Client::count($this->tenantId), 'the re-import duplicated clients');
        self::assertSame(48, $second['updated']);
        self::assertSame(0, $second['created']);
        self::assertSame(0, $second['clients_created']);
    }

    public function testAnEditedRowUpdatesInPlaceOnReimport(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];
        $this->importer->commit($this->tenantId, $csv, $mapping);

        // INV-1005 carries the "$1,250.00" amount; raise it and re-import.
        $originalLine = 'INV-1005,Bill Payer," bill@bigco.test ","BigCo Ltd","$1,250.00",2026-06-04,Paid,';
        self::assertStringContainsString($originalLine, $csv, 'the fixture line to edit was not found');

        $edited = str_replace(
            $originalLine,
            'INV-1005,Bill Payer," bill@bigco.test ","BigCo Ltd","$9,999.99",2026-06-04,Paid,',
            $csv
        );

        $this->importer->commit($this->tenantId, $edited, $mapping);

        self::assertSame(999999, (int) Invoice::findByNumber($this->tenantId, 'INV-1005')['amount_cents']);
        self::assertSame(48, Invoice::count($this->tenantId));
    }

    public function testARepeatedInvoiceNumberInsideOneFileIsReportedNotOverwritten(): void
    {
        $csv = "Invoice #,Email,Amount,Due Date\n"
            . "INV-X,a@test.test,100,2026-08-01\n"
            . "INV-X,b@test.test,200,2026-08-02\n";

        $result = $this->importer->commit(
            $this->tenantId,
            $csv,
            ColumnMapper::detect(['Invoice #', 'Email', 'Amount', 'Due Date'])
        );

        self::assertSame(1, $result['imported']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('also appears on line', $result['errors'][0]['reason']);
    }

    // ---------------------------------------------------- client matching

    public function testClientsAreMatchedOnEmailRatherThanDuplicated(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];
        $result = $this->importer->commit($this->tenantId, $csv, $mapping);

        // Five distinct people across 48 invoices.
        self::assertSame(5, Client::count($this->tenantId));
        self::assertSame(5, $result['clients_created']);
        self::assertSame(43, $result['clients_matched']);
    }

    public function testEmailMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        $csv = "Invoice #,Email,Amount,Due Date\n"
            . "INV-1,\" Bill@BigCo.test \",100,2026-08-01\n"
            . "INV-2,bill@bigco.test,200,2026-08-02\n"
            . "INV-3,BILL@BIGCO.TEST,300,2026-08-03\n";

        $this->importer->commit(
            $this->tenantId,
            $csv,
            ColumnMapper::detect(['Invoice #', 'Email', 'Amount', 'Due Date'])
        );

        self::assertSame(1, Client::count($this->tenantId), 'the same person became several clients');
        self::assertSame(3, Invoice::count($this->tenantId));
    }

    public function testAnImportMatchesAClientCreatedByHand(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill Payer', 'email' => 'bill@bigco.test']);

        $csv = "Invoice #,Email,Amount,Due Date\nINV-1,bill@bigco.test,100,2026-08-01\n";
        $this->importer->commit(
            $this->tenantId,
            $csv,
            ColumnMapper::detect(['Invoice #', 'Email', 'Amount', 'Due Date'])
        );

        self::assertSame(1, Client::count($this->tenantId));
        self::assertSame($clientId, (int) Invoice::findByNumber($this->tenantId, 'INV-1')['client_id']);
    }

    // ------------------------------------------ self-check: tenant scoping

    public function testAnotherTenantsInvoicesAreNeverVisible(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];
        $this->importer->commit($this->tenantId, $csv, $mapping);

        self::assertSame(0, Invoice::count($this->otherTenantId));
        self::assertSame(0, Client::count($this->otherTenantId));
        self::assertSame([], Invoice::listWithContext($this->otherTenantId));
        self::assertNull(Invoice::findByNumber($this->otherTenantId, 'INV-1001'));
        self::assertSame(0, Invoice::statusTallies($this->otherTenantId)['all']);

        $invoiceId = (int) Invoice::findByNumber($this->tenantId, 'INV-1001')['id'];
        self::assertNull(Invoice::find($this->otherTenantId, $invoiceId));
        self::assertNull(Invoice::withClient($this->otherTenantId, $invoiceId));
        self::assertFalse(Invoice::update($this->otherTenantId, $invoiceId, ['notes' => 'HACKED']));
        self::assertFalse(Invoice::delete($this->otherTenantId, $invoiceId));
        self::assertNotSame('HACKED', Invoice::find($this->tenantId, $invoiceId)['notes']);
    }

    public function testTheSameFileInTwoTenantsProducesTwoIndependentSets(): void
    {
        $csv = $this->messyCsv();
        $mapping = $this->importer->preview($csv)['mapping'];

        $this->importer->commit($this->tenantId, $csv, $mapping);
        $this->importer->commit($this->otherTenantId, $csv, $mapping);

        self::assertSame(48, Invoice::count($this->tenantId));
        self::assertSame(48, Invoice::count($this->otherTenantId));

        $mine = Invoice::findByNumber($this->tenantId, 'INV-1001');
        $theirs = Invoice::findByNumber($this->otherTenantId, 'INV-1001');

        self::assertNotSame($mine['id'], $theirs['id'], 'the two tenants share a row');

        Invoice::update($this->tenantId, (int) $mine['id'], ['notes' => 'mine only']);
        self::assertNotSame('mine only', Invoice::findByNumber($this->otherTenantId, 'INV-1001')['notes']);
    }

    // ------------------------------------------------------- invoice list

    public function testTheListComputesDaysOverdueAndSortsByIt(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        foreach ([['INV-OLD', '-40 days'], ['INV-MID', '-10 days'], ['INV-FUTURE', '+10 days']] as [$number, $offset]) {
            Invoice::create($this->tenantId, [
                'client_id' => $clientId,
                'number' => $number,
                'amount_cents' => 10000,
                'currency' => 'USD',
                'due_date' => date('Y-m-d', strtotime($offset)),
            ]);
        }

        $list = Invoice::listWithContext($this->tenantId, ['sort' => 'days_overdue']);

        self::assertSame('INV-OLD', $list[0]['number'], 'most overdue must sort first');
        self::assertSame(40, (int) $list[0]['days_overdue']);
        self::assertSame(10, (int) $list[1]['days_overdue']);
        self::assertSame(-10, (int) $list[2]['days_overdue'], 'a future invoice is negative days overdue');
    }

    public function testTheListFiltersByStatusIncludingTheOverdueView(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);

        Invoice::create($this->tenantId, ['client_id' => $clientId, 'number' => 'A', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => date('Y-m-d', strtotime('-5 days'))]);
        Invoice::create($this->tenantId, ['client_id' => $clientId, 'number' => 'B', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => date('Y-m-d', strtotime('+5 days'))]);
        Invoice::create($this->tenantId, ['client_id' => $clientId, 'number' => 'C', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => date('Y-m-d', strtotime('-5 days')), 'status' => 'paid']);

        $tallies = Invoice::statusTallies($this->tenantId);
        self::assertSame(3, $tallies['all']);
        self::assertSame(2, $tallies['open']);
        self::assertSame(1, $tallies['overdue'], 'a paid invoice is not overdue');
        self::assertSame(1, $tallies['paid']);

        $overdue = Invoice::listWithContext($this->tenantId, ['status' => 'overdue']);
        self::assertCount(1, $overdue);
        self::assertSame('A', $overdue[0]['number']);
    }

    public function testTheListShowsChaseStatusInline(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);
        $invoiceId = Invoice::create($this->tenantId, [
            'client_id' => $clientId, 'number' => 'INV-1', 'amount_cents' => 100,
            'currency' => 'USD', 'due_date' => '2026-08-01',
        ]);

        $before = Invoice::listWithContext($this->tenantId);
        self::assertNull($before[0]['chase_status'], 'no chase means no chase status');

        $sequenceId = \Keel\App\Models\Sequence::createWithSteps($this->tenantId, ['name' => 'Ladder'], [
            ['offset_days' => 7, 'subject_template' => 'S', 'body_template' => 'B'],
        ]);
        \Keel\App\Models\Chase::start($this->tenantId, $invoiceId, $sequenceId, null, new \DateTimeImmutable('2026-08-08'), 1);

        $after = Invoice::listWithContext($this->tenantId);
        self::assertSame('scheduled', $after[0]['chase_status']);
        self::assertNotNull($after[0]['chase_next_send_at']);
    }

    public function testAnInjectedSortKeyIsIgnoredRatherThanExecuted(): void
    {
        $clientId = Client::create($this->tenantId, ['name' => 'Bill', 'email' => 'bill@bigco.test']);
        Invoice::create($this->tenantId, [
            'client_id' => $clientId, 'number' => 'INV-1', 'amount_cents' => 100,
            'currency' => 'USD', 'due_date' => '2026-08-01',
        ]);

        $list = Invoice::listWithContext($this->tenantId, ['sort' => "'; DROP TABLE invoices; --"]);

        self::assertCount(1, $list);

        $exists = Database::connection()->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
        self::assertNotFalse($exists, 'the invoices table did not survive');
    }

    // -------------------------------------------------------------- helpers

    /**
     * A 50-row export with mixed date and money notation, quoted commas, a
     * blank line, and exactly two unusable rows.
     */
    private function messyCsv(): string
    {
        $lines = ['Invoice #,Client Name,Customer Email,Company,Amount Due,Due Date,Status,Notes'];

        $people = [
            ['Bill Payer', 'bill@bigco.test', 'BigCo Ltd'],
            ['Renee Dubois', 'renee@atelier.test', 'Atelier'],
            ['Sam Chen', 'sam@chen-studio.test', 'Chen Studio'],
            ['Ana Garcia', 'ana@garcia.test', 'Garcia and Co'],
            ['Tom Blake', 'tom@blake.test', ''],
        ];

        $amounts = ['"$1,250.00"', '523.50', '890', '"USD 744.00"', '"3,978"'];
        $dates = ['2026-06-04', '06/07/2026', '"Jun 7, 2026"', '10 Jun 2026', '06-13-2026'];

        for ($i = 1; $i <= 48; $i++) {
            $person = $people[$i % 5];
            $email = $i % 4 === 0 ? strtoupper($person[1]) : ' ' . $person[1] . ' ';

            $lines[] = implode(',', [
                'INV-' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                $person[0],
                '"' . $email . '"',
                '"' . $person[2] . '"',
                $amounts[$i % 5],
                $dates[$i % 5],
                $i % 5 === 0 ? 'Paid' : ($i % 3 === 0 ? 'Unpaid' : 'Open'),
                $i % 4 === 0 ? '"Deposit invoice, balance to follow"' : '',
            ]);

            if ($i === 20) {
                $lines[] = '';
            }
        }

        // Two rows that cannot be salvaged.
        $lines[] = 'INV-9001,Broken Amount,broken@bigco.test,BigCo,to be confirmed,2026-07-01,Open,';
        $lines[] = 'INV-9002,Broken Email,not-an-email-at-all,Nowhere,"$500.00",2026-07-15,Open,';

        return implode("\n", $lines) . "\n";
    }
}
