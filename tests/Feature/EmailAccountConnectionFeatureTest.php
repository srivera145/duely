<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\EmailAccount;
use Keel\App\Services\AppPasswordGuidance;
use Keel\App\Services\ConnectionDiagnosis;
use Keel\App\Services\Crypto;
use Keel\App\Services\CryptoException;
use Keel\App\Services\ImapClient;
use Keel\App\Services\MailAccountService;
use Keel\App\Services\ProviderPresets;
use Keel\App\Services\SmtpProbe;
use Keel\Core\Database;
use Tests\Support\FakeMailServer;
use Tests\TestCase;

/**
 * Covers the guarantees the connect flow makes: credentials are proven before
 * they are stored, stored only as ciphertext, never echoed back, and every
 * failure is reported as a sentence a person can act on.
 *
 * The SMTP and IMAP legs run against throwaway servers on loopback, so these
 * are real protocol conversations rather than mocked returns.
 */
class EmailAccountConnectionFeatureTest extends TestCase
{
    private const APP_PASSWORD = 'abcd efgh ijkl mnop';

    private int $tenantId;
    private int $userId;
    private MailAccountService $service;

    /** @var FakeMailServer[] */
    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $organization = $this->createOrganization('Acme Design');
        $this->tenantId = (int) $organization['id'];

        $connection = Database::connection();
        $insert = $connection->prepare('INSERT INTO users (email, name, organization_id) VALUES (?, ?, ?)');
        $insert->execute(['ada@studio.test', 'Ada Lovelace', $this->tenantId]);
        $this->userId = (int) $connection->lastInsertId();

        $this->service = new MailAccountService();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------- crypto

    public function testCredentialsAreEncryptedWithAuthenticatedAesGcm(): void
    {
        $envelope = Crypto::encrypt(self::APP_PASSWORD);

        self::assertStringNotContainsString(self::APP_PASSWORD, $envelope);
        self::assertSame(self::APP_PASSWORD, Crypto::decrypt($envelope));

        // version byte + 12-byte IV + 16-byte tag + ciphertext
        self::assertSame(1 + 12 + 16 + strlen(self::APP_PASSWORD), strlen($envelope));
        self::assertSame("\x01", $envelope[0]);
    }

    public function testEachEncryptionUsesAFreshIv(): void
    {
        self::assertNotSame(Crypto::encrypt('same'), Crypto::encrypt('same'));
    }

    public function testDecryptionFailsLoudlyWhenTheAuthTagDoesNotVerify(): void
    {
        $envelope = Crypto::encrypt(self::APP_PASSWORD);

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('authentication tag');

        Crypto::decrypt(substr($envelope, 0, -1) . 'X');
    }

    public function testDecryptionFailsLoudlyUnderTheWrongKey(): void
    {
        $envelope = Crypto::encrypt(self::APP_PASSWORD);

        $original = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
        $_ENV['APP_ENCRYPTION_KEY'] = Crypto::generateKey();

        try {
            $this->expectException(CryptoException::class);
            Crypto::decrypt($envelope);
        } finally {
            $_ENV['APP_ENCRYPTION_KEY'] = $original;
        }
    }

    // ---------------------------------------------------------- presets

    public function testKnownProvidersPrefillTheirRealHosts(): void
    {
        $expectations = [
            'ada@gmail.com' => ['smtp.gmail.com', 'imap.gmail.com', 'gmail'],
            'ada@outlook.com' => ['smtp-mail.outlook.com', 'outlook.office365.com', 'outlook'],
            'ada@hotmail.com' => ['smtp-mail.outlook.com', 'outlook.office365.com', 'outlook'],
            'ada@live.com' => ['smtp-mail.outlook.com', 'outlook.office365.com', 'outlook'],
            'ada@yahoo.com' => ['smtp.mail.yahoo.com', 'imap.mail.yahoo.com', 'smtp'],
            'ada@fastmail.com' => ['smtp.fastmail.com', 'imap.fastmail.com', 'smtp'],
            'ada@zoho.com' => ['smtp.zoho.com', 'imap.zoho.com', 'smtp'],
            'ada@icloud.com' => ['smtp.mail.me.com', 'imap.mail.me.com', 'smtp'],
        ];

        foreach ($expectations as $email => [$smtpHost, $imapHost, $provider]) {
            $preset = ProviderPresets::forEmail($email);

            self::assertSame($smtpHost, $preset['smtp_host'], $email);
            self::assertSame($imapHost, $preset['imap_host'], $email);
            self::assertSame($provider, $preset['provider'], $email);
            self::assertSame($email, $preset['smtp_username'], $email);
        }
    }

    public function testUnknownDomainsFallBackToTheCpanelConventionAndSaySo(): void
    {
        $preset = ProviderPresets::forEmail('billing@some-unknown-domain.invalid');

        self::assertSame('mail.some-unknown-domain.invalid', $preset['smtp_host']);
        self::assertSame('mail.some-unknown-domain.invalid', $preset['imap_host']);
        self::assertFalse($preset['confident'], 'a guess must be flagged as a guess');
        self::assertNotEmpty($preset['note']);
    }

    public function testGmailAndOutlookAreFlaggedAsNeedingAnAppPasswordUpFront(): void
    {
        self::assertTrue(ProviderPresets::requiresAppPassword('ada@gmail.com'));
        self::assertTrue(ProviderPresets::requiresAppPassword('ada@outlook.com'));
        self::assertNotNull(AppPasswordGuidance::preflightNotice('gmail'));
        self::assertNull(AppPasswordGuidance::preflightNotice('smtp'));
    }

    // ------------------------------------------------ live connection tests

    public function testWorkingCredentialsPassBothLegsAndSave(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $result = $this->service->save($this->tenantId, $this->userId, $this->input($server));

        self::assertTrue($result['saved'], json_encode([$result['smtp'], $result['imap']]));
        self::assertTrue($result['smtp']['ok']);
        self::assertTrue($result['imap']['ok']);

        $account = EmailAccount::find($this->tenantId, (int) $result['account_id']);
        self::assertSame(EmailAccount::STATUS_ACTIVE, $account['status']);
        self::assertNotNull($account['last_verified_at']);
    }

    public function testASavedAccountCanActuallySendAMessage(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($server));

        $diagnosis = $this->service->sendTestMessage(
            $this->tenantId,
            (int) $saved['account_id'],
            'ada@studio.test',
            'Ada Lovelace'
        );

        self::assertTrue($diagnosis->succeeded(), $diagnosis->message);

        $delivered = $server->deliveredMessage();
        self::assertNotNull($delivered, 'the server never received a message');
        self::assertStringContainsString('Ada Lovelace', $delivered);
        self::assertStringContainsString('ada@studio.test', $delivered);
        self::assertStringContainsStringIgnoringCase('Reply-To:', $delivered);
        self::assertStringNotContainsString(self::APP_PASSWORD, $delivered);
    }

    public function testASavedAccountCanActuallyReadTheInbox(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($server));
        $account = EmailAccount::find($this->tenantId, (int) $saved['account_id']);

        $imap = new ImapClient();

        try {
            self::assertTrue($imap->connect('127.0.0.1', $server->imapPort, 'none')->succeeded());
            self::assertTrue($imap->login('ada@studio.test', (string) EmailAccount::imapPassword($account))->succeeded());
            self::assertTrue($imap->select('INBOX')->succeeded());
            self::assertSame([101, 102, 103], $imap->searchUids('ALL'));

            $headers = $imap->fetchHeaders(101);
            self::assertIsString($headers);
            self::assertStringContainsString('bill@bigco.test', $headers);
            self::assertStringContainsString('reply-9001@bigco.test', $headers);
        } finally {
            $imap->disconnect();
        }
    }

    public function testAWrongPasswordGivesAReadableErrorAndSavesNothing(): void
    {
        $server = $this->server(FakeMailServer::MODE_BAD_PASSWORD);
        $input = $this->input($server);
        $input['smtp_password'] = 'wrong-password';
        $input['imap_password'] = 'wrong-password';

        $result = $this->service->save($this->tenantId, $this->userId, $input);

        self::assertFalse($result['saved']);
        self::assertSame(ConnectionDiagnosis::AUTH_FAILED, $result['smtp']['code']);
        self::assertSame(ConnectionDiagnosis::AUTH_FAILED, $result['imap']['code']);

        foreach ([$result['smtp']['message'], $result['imap']['message']] as $message) {
            self::assertStringContainsStringIgnoringCase('rejected', $message);
            self::assertStringNotContainsString('Exception', $message);
            self::assertStringNotContainsString('.php', $message);
            self::assertStringNotContainsString('#0', $message);
        }

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM email_accounts WHERE tenant_id = ?');
        $count->execute([$this->tenantId]);
        self::assertSame(0, (int) $count->fetchColumn(), 'a failed test must write nothing');
    }

    public function testAFailedEditLeavesTheWorkingAccountCompletelyUntouched(): void
    {
        $good = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($good));
        $accountId = (int) $saved['account_id'];

        $bad = $this->server(FakeMailServer::MODE_BAD_PASSWORD);
        $edit = $this->input($bad);
        $edit['account_id'] = $accountId;
        $edit['from_name'] = 'Should Not Persist';
        $edit['smtp_password'] = 'wrong-password';
        $edit['imap_password'] = 'wrong-password';

        $result = $this->service->save($this->tenantId, $this->userId, $edit);
        self::assertFalse($result['saved']);

        $account = EmailAccount::find($this->tenantId, $accountId);
        self::assertSame('Ada Lovelace', $account['from_name'], 'a rejected edit must not persist any field');
        self::assertSame(EmailAccount::STATUS_ACTIVE, $account['status']);
        self::assertSame(self::APP_PASSWORD, EmailAccount::smtpPassword($account));
    }

    public function testGmailAuthFailureReturnsAppPasswordInstructionsWithALink(): void
    {
        $server = $this->server(FakeMailServer::MODE_APP_PASSWORD);
        $input = $this->input($server);
        $input['from_email'] = 'ada@gmail.com';
        $input['provider'] = 'gmail';
        $input['smtp_password'] = 'my-normal-google-password';
        $input['imap_password'] = 'my-normal-google-password';

        $result = $this->service->test($this->tenantId, $input);

        self::assertSame(ConnectionDiagnosis::APP_PASSWORD_REQUIRED, $result['smtp']['code']);
        self::assertSame(ConnectionDiagnosis::APP_PASSWORD_REQUIRED, $result['imap']['code']);

        $guidance = $result['guidance'];
        self::assertIsArray($guidance, 'the UI needs inline instructions here');
        self::assertStringContainsString('app password', strtolower($guidance['title']));
        self::assertGreaterThanOrEqual(3, count($guidance['steps']));
        self::assertSame('https://myaccount.google.com/apppasswords', $guidance['link_url']);
    }

    public function testAnUnknownHostAndABlockedPortAreReportedDifferently(): void
    {
        $smtp = new SmtpProbe();

        $unreachable = $smtp->test('no-such-host.invalid', 587, 'tls', 'a@b.test', 'pw');
        self::assertSame(ConnectionDiagnosis::HOST_UNREACHABLE, $unreachable->code);
        self::assertStringContainsString('no-such-host.invalid', $unreachable->message);

        // Reserve then release a port so nothing is listening on it.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        $closedPort = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $blocked = $smtp->test('127.0.0.1', $closedPort, 'tls', 'a@b.test', 'pw');
        self::assertSame(ConnectionDiagnosis::PORT_BLOCKED, $blocked->code);
        self::assertStringContainsString((string) $closedPort, $blocked->message);
        self::assertNotEmpty($blocked->hint, 'a blocked port needs a suggested next step');
    }

    public function testANonImapServiceOnThePortIsNamedAsSuch(): void
    {
        $server = $this->server(FakeMailServer::MODE_NOT_IMAP);
        $result = (new ImapClient())->test('127.0.0.1', $server->imapPort, 'none', 'a@b.test', 'pw');

        self::assertSame(ConnectionDiagnosis::PORT_BLOCKED, $result->code);
        self::assertStringContainsStringIgnoringCase('not an IMAP server', $result->message);
    }

    public function testAMissingMailboxIsReportedAsSuch(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $result = (new ImapClient())->test(
            '127.0.0.1',
            $server->imapPort,
            'none',
            'ada@studio.test',
            self::APP_PASSWORD,
            'Archive'
        );

        self::assertSame(ConnectionDiagnosis::MAILBOX_MISSING, $result->code);
        self::assertStringContainsString('Archive', $result->message);
    }

    // --------------------------------------------------- secret containment

    public function testTheStoredColumnIsUnreadableInARawSelect(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($server));

        $statement = Database::connection()->prepare('SELECT * FROM email_accounts WHERE id = ?');
        $statement->execute([(int) $saved['account_id']]);
        $row = $statement->fetch();

        $dump = '';
        foreach ($row as $column => $value) {
            $dump .= $column . '=' . (string) $value . "\n";
        }

        self::assertStringNotContainsString(self::APP_PASSWORD, $dump);
        self::assertSame("\x01", substr((string) $row['smtp_password_encrypted'], 0, 1));
        self::assertNotSame(
            $row['smtp_password_encrypted'],
            $row['imap_password_encrypted'],
            'identical plaintexts must not produce identical ciphertexts'
        );
    }

    public function testNoResponseOrRenderedFormEverCarriesTheCredential(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($server));

        $state = $this->service->formState($this->tenantId, [
            'id' => $this->userId,
            'email' => 'ada@studio.test',
            'name' => 'Ada Lovelace',
        ]);

        foreach (['save response' => $saved, 'form state' => $state] as $label => $payload) {
            $json = (string) json_encode($payload);
            self::assertStringNotContainsString(self::APP_PASSWORD, $json, $label);
            self::assertStringNotContainsString('password_encrypted', $json, $label);
        }

        // Only booleans describe the stored secret.
        self::assertTrue($state['has_smtp_password']);
        self::assertTrue($state['has_imap_password']);
        self::assertSame(MailAccountService::MASKED_PLACEHOLDER, $state['masked_placeholder']);
    }

    public function testResubmittingTheMaskKeepsTheStoredPassword(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($server));
        $accountId = (int) $saved['account_id'];

        $edit = $this->input($server);
        $edit['account_id'] = $accountId;
        $edit['from_name'] = 'Ada L.';
        $edit['smtp_password'] = MailAccountService::MASKED_PLACEHOLDER;
        $edit['imap_password'] = MailAccountService::MASKED_PLACEHOLDER;

        $result = $this->service->save($this->tenantId, $this->userId, $edit);

        self::assertTrue($result['saved']);

        $account = EmailAccount::find($this->tenantId, $accountId);
        self::assertSame(self::APP_PASSWORD, EmailAccount::smtpPassword($account), 'the mask wiped the password');
        self::assertSame('Ada L.', $account['from_name']);
    }

    public function testAnEmptyPasswordSubmissionAlsoKeepsTheStoredPassword(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($server));
        $accountId = (int) $saved['account_id'];

        $edit = $this->input($server);
        $edit['account_id'] = $accountId;
        $edit['smtp_password'] = '';
        $edit['imap_password'] = '';

        $this->service->save($this->tenantId, $this->userId, $edit);

        self::assertSame(
            self::APP_PASSWORD,
            EmailAccount::smtpPassword(EmailAccount::find($this->tenantId, $accountId))
        );
    }

    // ------------------------------------------------------------ defaults

    public function testSendAsNameAndReplyToDefaultToTheUser(): void
    {
        $state = $this->service->formState($this->tenantId, [
            'id' => $this->userId,
            'email' => 'ada@studio.test',
            'name' => 'Ada Lovelace',
        ]);

        self::assertFalse($state['exists']);
        self::assertSame('Ada Lovelace', $state['from_name']);
        self::assertSame('ada@studio.test', $state['from_email']);
        self::assertSame('ada@studio.test', $state['reply_to']);
    }

    public function testABrokenAccountSurfacesNeedsReauthWithAReadableReason(): void
    {
        $server = $this->server(FakeMailServer::MODE_GOOD);
        $saved = $this->service->save($this->tenantId, $this->userId, $this->input($server));

        $this->service->markNeedsReauth(
            $this->tenantId,
            (int) $saved['account_id'],
            ConnectionDiagnosis::failure('smtp', ConnectionDiagnosis::AUTH_FAILED, 'The server rejected that username and password.')
        );

        $state = $this->service->formState($this->tenantId, ['id' => $this->userId, 'email' => 'ada@studio.test']);

        self::assertSame(EmailAccount::STATUS_NEEDS_REAUTH, $state['status']);
        self::assertStringContainsString('rejected', (string) $state['last_error']);
        self::assertStringNotContainsString(self::APP_PASSWORD, (string) $state['last_error']);
    }

    // -------------------------------------------------------------- helpers

    private function server(string $mode): FakeMailServer
    {
        $server = FakeMailServer::start($mode);
        $this->servers[] = $server;

        return $server;
    }

    private function input(FakeMailServer $server): array
    {
        return [
            'from_name' => 'Ada Lovelace',
            'from_email' => 'ada@studio.test',
            'reply_to' => 'ada@studio.test',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => $server->smtpPort,
            'smtp_encryption' => 'none',
            'smtp_username' => 'ada@studio.test',
            'smtp_password' => self::APP_PASSWORD,
            'imap_host' => '127.0.0.1',
            'imap_port' => $server->imapPort,
            'imap_encryption' => 'none',
            'imap_username' => 'ada@studio.test',
            'imap_password' => self::APP_PASSWORD,
            'imap_folder' => 'INBOX',
        ];
    }
}
