<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use InvalidArgumentException;
use Keel\App\Models\Chase;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\Client;
use Keel\App\Models\EmailAccount;
use Keel\App\Models\Invoice;
use Keel\App\Models\ReplyEvent;
use Keel\App\Models\Sequence;
use Keel\App\Models\SequenceStep;
use Keel\App\Services\Crypto;
use Keel\Core\Database;
use PDOException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the invariants the Duely domain layer is built on: strict tenant
 * scoping, integer-cent money, secrets encrypted at rest, one chase per invoice,
 * offsets measured from the due date, and idempotent reply ingestion.
 */
class DuelyDomainModelFeatureTest extends TestCase
{
    private const MODELS = [
        Client::class,
        Invoice::class,
        EmailAccount::class,
        Sequence::class,
        SequenceStep::class,
        Chase::class,
        ChaseMessage::class,
        ReplyEvent::class,
    ];

    private int $tenantA;
    private int $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = (int) $this->createOrganization('Acme Design')['id'];
        $this->tenantB = (int) $this->createOrganization('Rival Studio')['id'];
    }

    // ------------------------------------------------------- tenant scoping

    public function testTenantCannotReadWriteOrDeleteAnotherTenantsRows(): void
    {
        $clientA = Client::findOrCreate($this->tenantA, 'bill@bigco.test', ['name' => 'Bill Payer']);

        self::assertNotNull(Client::find($this->tenantA, $clientA));
        self::assertNull(Client::find($this->tenantB, $clientA), 'cross-tenant read leaked a row');
        self::assertFalse(Client::update($this->tenantB, $clientA, ['name' => 'HACKED']));
        self::assertSame('Bill Payer', Client::find($this->tenantA, $clientA)['name']);
        self::assertFalse(Client::delete($this->tenantB, $clientA));
        self::assertTrue(Client::exists($this->tenantA, $clientA));
        self::assertSame([], Client::findMany($this->tenantB, [$clientA]));
    }

    public function testTenantIdCannotBeReassignedThroughTheAttributeArray(): void
    {
        $clientA = Client::findOrCreate($this->tenantA, 'bill@bigco.test', ['name' => 'Bill Payer']);

        Client::update($this->tenantA, $clientA, ['tenant_id' => $this->tenantB, 'name' => 'Still Mine']);

        self::assertNotNull(Client::find($this->tenantA, $clientA), 'row escaped its tenant');
        self::assertNull(Client::find($this->tenantB, $clientA));
    }

    public function testEveryPublicModelMethodThatTouchesTheDatabaseTakesATenantId(): void
    {
        // The two scheduler fan-out helpers are the documented exception: they
        // return tenant ids only, never a tenant-owned row.
        $allowed = ['Chase::tenantsWithWorkDue', 'ReplyEvent::tenantsWithUnprocessed'];
        $offenders = [];

        foreach (self::MODELS as $model) {
            $reflection = new ReflectionClass($model);
            $shortName = $reflection->getShortName();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isStatic()) {
                    continue;
                }

                $parameters = $method->getParameters();
                $first = $parameters[0] ?? null;

                if ($first?->getName() === 'tenantId' && $first->getType()?->getName() === 'int') {
                    continue;
                }

                $name = $shortName . '::' . $method->getName();

                if (in_array($name, $allowed, true) || !$this->methodTouchesDatabase($method)) {
                    continue;
                }

                $offenders[] = $name;
            }
        }

        self::assertSame([], $offenders, 'these methods reach tenant data without a tenant id');
    }

    public function testModelsContainNoStringInterpolatedSql(): void
    {
        $sqlPattern = '/\b(SELECT|INSERT\s+INTO|INSERT\s+IGNORE|UPDATE|DELETE\s+FROM)\b/i';
        $offenders = [];

        foreach (glob(self::$basePath . '/src/App/Models/*.php') as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || $token[0] !== T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }

                if (preg_match($sqlPattern, $token[1]) === 1) {
                    $offenders[] = basename($file) . ':' . $token[2];
                }
            }
        }

        self::assertSame([], $offenders, 'SQL built by string interpolation');
    }

    public function testUnknownColumnNamesAreRejectedRatherThanInterpolated(): void
    {
        $findOneBy = new ReflectionMethod(Client::class, 'findOneBy');

        $this->expectException(InvalidArgumentException::class);
        $findOneBy->invoke(null, $this->tenantA, "email' OR 1=1 --", 'x');
    }

    public function testDeletingATenantCascadesEveryDuelyTable(): void
    {
        $this->seedFullChase($this->tenantA);

        $connection = Database::connection();
        $delete = $connection->prepare('DELETE FROM organizations WHERE id = ?');
        $delete->execute([$this->tenantA]);

        foreach (['clients', 'invoices', 'email_accounts', 'sequences', 'sequence_steps', 'chases', 'chase_messages', 'reply_events'] as $table) {
            $count = $connection->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE tenant_id = ?');
            $count->execute([$this->tenantA]);

            self::assertSame(0, (int) $count->fetchColumn(), $table . ' did not cascade');
        }
    }

    // --------------------------------------------------------------- money

    public function testMoneyParsesToExactIntegerCents(): void
    {
        self::assertSame(123450, Invoice::centsFromDecimal('1234.50'));
        self::assertSame(123450, Invoice::centsFromDecimal('1,234.5'));
        self::assertSame(9900, Invoice::centsFromDecimal('99'));
        self::assertSame(7, Invoice::centsFromDecimal('0.07'));
        self::assertSame(-5025, Invoice::centsFromDecimal('-50.25'));
    }

    public function testMoneyRejectsSubCentPrecision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Invoice::centsFromDecimal('12.345');
    }

    public function testInvoiceAmountsSurviveTheRoundTripAsIntegers(): void
    {
        $clientId = Client::findOrCreate($this->tenantA, 'bill@bigco.test', ['name' => 'Bill Payer']);
        $invoiceId = Invoice::create($this->tenantA, [
            'client_id' => $clientId,
            'number' => 'INV-001',
            'amount_cents' => 250000,
            'currency' => 'USD',
            'due_date' => '2026-08-05',
        ]);

        self::assertSame(250000, (int) Invoice::find($this->tenantA, $invoiceId)['amount_cents']);
        self::assertSame(['USD' => 250000], Invoice::outstandingByCurrency($this->tenantA));
    }

    public function testInvoiceNumbersAreUniquePerTenantButReusableAcrossTenants(): void
    {
        $clientA = Client::findOrCreate($this->tenantA, 'bill@bigco.test', ['name' => 'Bill']);
        $clientB = Client::findOrCreate($this->tenantB, 'bill@bigco.test', ['name' => 'Bill']);

        Invoice::create($this->tenantA, ['client_id' => $clientA, 'number' => 'INV-001', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => '2026-08-05']);
        Invoice::create($this->tenantB, ['client_id' => $clientB, 'number' => 'INV-001', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => '2026-08-05']);

        $this->expectException(PDOException::class);
        Invoice::create($this->tenantA, ['client_id' => $clientA, 'number' => 'INV-001', 'amount_cents' => 100, 'currency' => 'USD', 'due_date' => '2026-09-05']);
    }

    // ------------------------------------------ offsets from the due date

    public function testAnInvoiceImportedEighteenDaysOverdueEntersAtTheCorrectRung(): void
    {
        $sequenceId = $this->seedSequence($this->tenantA);
        $clientId = Client::findOrCreate($this->tenantA, 'bill@bigco.test', ['name' => 'Bill']);
        $invoiceId = Invoice::create($this->tenantA, [
            'client_id' => $clientId,
            'number' => 'INV-LATE',
            'amount_cents' => 250000,
            'currency' => 'USD',
            'due_date' => '2026-08-05',
        ]);

        $daysOverdue = Invoice::daysOverdue(
            Invoice::find($this->tenantA, $invoiceId),
            new DateTimeImmutable('2026-08-23')
        );
        self::assertSame(18, $daysOverdue);

        $entry = SequenceStep::entryStep($this->tenantA, $sequenceId, $daysOverdue);
        self::assertNotNull($entry);
        self::assertSame(14, (int) $entry['offset_days'], 'should enter at the day-14 rung');
        self::assertSame(4, (int) $entry['position'], 'should not restart at step 1');

        self::assertSame(30, (int) SequenceStep::nextPendingStep($this->tenantA, $sequenceId, $daysOverdue)['offset_days']);
    }

    public function testAnInvoiceNotYetDueHasNoEntryRung(): void
    {
        $sequenceId = $this->seedSequence($this->tenantA);

        self::assertNull(SequenceStep::entryStep($this->tenantA, $sequenceId, -10));
        self::assertSame(-3, (int) SequenceStep::nextPendingStep($this->tenantA, $sequenceId, -10)['offset_days']);
    }

    public function testSendTimesAreComputedFromTheDueDateNotTheSendDate(): void
    {
        $sequenceId = $this->seedSequence($this->tenantA);
        $steps = SequenceStep::forSequence($this->tenantA, $sequenceId);

        self::assertSame('2026-08-02', SequenceStep::sendAtFor($steps[0], '2026-08-05')->format('Y-m-d'));
        self::assertSame('2026-08-12', SequenceStep::sendAtFor($steps[2], '2026-08-05')->format('Y-m-d'));
    }

    // ----------------------------------------------------- secrets at rest

    public function testEmailAccountSecretsAreNeverStoredInPlaintext(): void
    {
        $accountId = EmailAccount::create($this->tenantA, [
            'from_name' => 'Ada Freelance',
            'from_email' => 'ada@studio.test',
            'smtp_host' => 'smtp.test',
            'smtp_username' => 'ada',
            'smtp_password' => 'super-secret-smtp',
            'imap_host' => 'imap.test',
            'imap_username' => 'ada',
            'imap_password' => 'super-secret-imap',
            'status' => EmailAccount::STATUS_ACTIVE,
        ]);

        $raw = $this->rawEmailAccountRow($accountId);
        self::assertStringNotContainsString('super-secret-smtp', (string) $raw['smtp_password_encrypted']);
        self::assertStringNotContainsString('super-secret-imap', (string) $raw['imap_password_encrypted']);

        $row = EmailAccount::find($this->tenantA, $accountId);
        self::assertSame('super-secret-smtp', EmailAccount::smtpPassword($row));
        self::assertSame('super-secret-imap', EmailAccount::imapPassword($row));
        self::assertArrayNotHasKey('smtp_password_encrypted', EmailAccount::redact($row));
    }

    public function testWritingTheCiphertextColumnDirectlyIsIgnored(): void
    {
        $accountId = EmailAccount::create($this->tenantA, [
            'from_name' => 'Ada',
            'from_email' => 'ada@studio.test',
            'smtp_password' => 'original',
        ]);
        $before = $this->rawEmailAccountRow($accountId)['smtp_password_encrypted'];

        EmailAccount::update($this->tenantA, $accountId, ['smtp_password_encrypted' => 'plaintext-sneak']);

        self::assertSame($before, $this->rawEmailAccountRow($accountId)['smtp_password_encrypted']);
    }

    public function testCryptoDetectsTamperedCiphertext(): void
    {
        $blob = Crypto::encrypt('hunter2');

        self::assertSame('hunter2', Crypto::decrypt($blob));
        self::assertNotSame(Crypto::encrypt('x'), Crypto::encrypt('x'), 'IV must be random per value');

        $this->expectExceptionMessage('Decryption failed');
        Crypto::decrypt(substr($blob, 0, -1) . 'X');
    }

    // ------------------------------------------------- one chase per invoice

    public function testAnInvoiceCanOnlyEverHaveOneChase(): void
    {
        ['invoiceId' => $invoiceId, 'sequenceId' => $sequenceId, 'chaseId' => $chaseId] = $this->seedFullChase($this->tenantA);

        $again = Chase::start($this->tenantA, $invoiceId, $sequenceId, null, new DateTimeImmutable('2026-09-01'), 1);
        self::assertSame($chaseId, $again, 'a second start must return the existing chase');

        $this->expectException(PDOException::class);
        Chase::create($this->tenantA, [
            'invoice_id' => $invoiceId,
            'sequence_id' => $sequenceId,
            'status' => Chase::STATUS_SCHEDULED,
        ]);
    }

    public function testPausingAChaseClearsItsScheduleAndRemovesItFromTheDueQueue(): void
    {
        ['chaseId' => $chaseId] = $this->seedFullChase($this->tenantA);

        Chase::pause($this->tenantA, $chaseId, Chase::PAUSE_CLIENT_REPLIED);
        $chase = Chase::find($this->tenantA, $chaseId);

        self::assertSame(Chase::STATUS_PAUSED, $chase['status']);
        self::assertSame(Chase::PAUSE_CLIENT_REPLIED, $chase['paused_reason']);
        self::assertNull($chase['next_send_at']);
        self::assertSame([], Chase::due($this->tenantA, new DateTimeImmutable('2030-01-01')));
    }

    // ------------------------------------------------------ RFC822 threading

    public function testFollowUpsThreadOntoTheRootMessage(): void
    {
        ['chaseId' => $chaseId, 'rootMessageId' => $rootMessageId] = $this->seedFullChase($this->tenantA);

        $chase = Chase::find($this->tenantA, $chaseId);
        self::assertSame($rootMessageId, $chase['root_message_id']);
        self::assertSame($rootMessageId, $chase['thread_id']);

        $references = ChaseMessage::referencesFor($this->tenantA, $chaseId, $chase['root_message_id']);
        self::assertSame(1, substr_count((string) $references, $rootMessageId), 'root id must appear once');

        $followUpId = ChaseMessage::create($this->tenantA, [
            'chase_id' => $chaseId,
            'position' => 2,
            'to_email' => 'bill@bigco.test',
            'from_email' => 'ada@studio.test',
            'subject' => 'Re: Invoice INV-001',
            'body_text' => 'Final notice.',
            'rfc_message_id' => ChaseMessage::newMessageId(),
            'in_reply_to' => $rootMessageId,
            'references_header' => $references,
        ]);

        self::assertSame($rootMessageId, ChaseMessage::find($this->tenantA, $followUpId)['in_reply_to']);
        self::assertSame($chaseId, ChaseMessage::chaseIdForMessageId($this->tenantA, $rootMessageId));
        self::assertNull(ChaseMessage::chaseIdForMessageId($this->tenantB, $rootMessageId));
    }

    // -------------------------------------------------- idempotent ingestion

    public function testTheSameInboundMessageIsOnlyRecordedOnce(): void
    {
        ['chaseId' => $chaseId, 'rootMessageId' => $rootMessageId] = $this->seedFullChase($this->tenantA);
        $inboundId = '<client-reply-abc@bigco.test>';

        $first = ReplyEvent::record($this->tenantA, [
            'chase_id' => $chaseId,
            'type' => ReplyEvent::TYPE_REPLY,
            'from_email' => 'bill@bigco.test',
            'rfc_message_id' => $inboundId,
            'in_reply_to' => $rootMessageId,
            'received_at' => '2026-08-23 10:00:00',
        ]);
        $duplicate = ReplyEvent::record($this->tenantA, [
            'chase_id' => $chaseId,
            'type' => ReplyEvent::TYPE_REPLY,
            'rfc_message_id' => $inboundId,
            'received_at' => '2026-08-23 10:00:00',
        ]);

        self::assertIsInt($first);
        self::assertNull($duplicate, 're-polling the same message must be a no-op');
        self::assertCount(1, ReplyEvent::forChase($this->tenantA, $chaseId));
        self::assertTrue(ReplyEvent::hasHumanReply($this->tenantA, $chaseId));

        ReplyEvent::markProcessed($this->tenantA, $first, 'paused_chase');
        self::assertSame([], ReplyEvent::unprocessed($this->tenantA));
    }

    public function testAutoRepliesDoNotCountAsAHumanReply(): void
    {
        ['chaseId' => $chaseId] = $this->seedFullChase($this->tenantA);

        ReplyEvent::record($this->tenantA, [
            'chase_id' => $chaseId,
            'type' => ReplyEvent::TYPE_AUTO_REPLY,
            'rfc_message_id' => '<ooo-1@bigco.test>',
            'received_at' => '2026-08-23 10:00:00',
        ]);

        self::assertFalse(ReplyEvent::hasHumanReply($this->tenantA, $chaseId));
    }

    // -------------------------------------------------------------- helpers

    private function seedSequence(int $tenantId): int
    {
        return Sequence::createWithSteps($tenantId, ['name' => 'Polite ladder', 'is_default' => 1], [
            ['offset_days' => -3, 'subject_template' => 'Invoice {{number}} due soon', 'body_template' => 'Heads up.'],
            ['offset_days' => 1, 'subject_template' => 'Invoice {{number}} is due', 'body_template' => 'Gentle nudge.'],
            ['offset_days' => 7, 'subject_template' => 'Following up on {{number}}', 'body_template' => 'Checking in.'],
            ['offset_days' => 14, 'subject_template' => 'Second follow-up: {{number}}', 'body_template' => 'Still outstanding.'],
            ['offset_days' => 30, 'subject_template' => 'Final notice: {{number}}', 'body_template' => 'Please advise.', 'is_final' => 1],
        ]);
    }

    /**
     * @return array{clientId:int, invoiceId:int, sequenceId:int, accountId:int, chaseId:int, rootMessageId:string}
     */
    private function seedFullChase(int $tenantId): array
    {
        $sequenceId = $this->seedSequence($tenantId);
        $clientId = Client::findOrCreate($tenantId, 'bill@bigco.test', ['name' => 'Bill Payer']);
        $invoiceId = Invoice::create($tenantId, [
            'client_id' => $clientId,
            'number' => 'INV-001',
            'amount_cents' => 250000,
            'currency' => 'USD',
            'due_date' => '2026-08-05',
        ]);
        $accountId = EmailAccount::create($tenantId, [
            'from_name' => 'Ada Freelance',
            'from_email' => 'ada@studio.test',
            'smtp_host' => 'smtp.test',
            'smtp_password' => 'secret',
            'status' => EmailAccount::STATUS_ACTIVE,
        ]);

        $entry = SequenceStep::entryStep($tenantId, $sequenceId, 18);
        $chaseId = Chase::start(
            $tenantId,
            $invoiceId,
            $sequenceId,
            $accountId,
            SequenceStep::sendAtFor($entry, '2026-08-05'),
            (int) $entry['position']
        );

        $rootMessageId = ChaseMessage::newMessageId();
        $messageId = ChaseMessage::create($tenantId, [
            'chase_id' => $chaseId,
            'sequence_step_id' => (int) $entry['id'],
            'position' => 1,
            'to_email' => 'bill@bigco.test',
            'from_email' => 'ada@studio.test',
            'subject' => 'Second follow-up: INV-001',
            'body_text' => 'Still outstanding.',
            'rfc_message_id' => $rootMessageId,
        ]);
        ChaseMessage::markSent($tenantId, $messageId);
        Chase::anchorThread($tenantId, $chaseId, $rootMessageId);

        return compact('clientId', 'invoiceId', 'sequenceId', 'accountId', 'chaseId', 'rootMessageId');
    }

    private function rawEmailAccountRow(int $id): array
    {
        $statement = Database::connection()->prepare(
            'SELECT smtp_password_encrypted, imap_password_encrypted FROM email_accounts WHERE id = ?'
        );
        $statement->execute([$id]);

        return $statement->fetch() ?: [];
    }

    private function methodTouchesDatabase(ReflectionMethod $method): bool
    {
        $file = (string) $method->getDeclaringClass()->getFileName();
        $lines = file($file) ?: [];
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        return preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $body) === 1
            || str_contains($body, 'Database::connection')
            || preg_match('/(static|parent)::(run|runPaged|find|create|update|delete)\b/', $body) === 1;
    }
}
