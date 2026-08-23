<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\EmailAccount;
use Keel\App\Services\MailAccountService;
use Keel\Core\Database;
use Tests\Support\FakeMailServer;
use Tests\TestCase;

/**
 * Exercises the settings page and its JSON endpoints through the real router,
 * so the auth and CSRF middleware that protect them are part of the test.
 */
class EmailAccountRoutesFeatureTest extends TestCase
{
    private const APP_PASSWORD = 'abcd efgh ijkl mnop';

    /** @var FakeMailServer[] */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];

        parent::tearDown();
    }

    public function testTheSettingsPageRequiresAuthentication(): void
    {
        $response = $this->get('/settings/email');

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->header('Location'));
    }

    public function testEveryEmailAccountEndpointRequiresAuthentication(): void
    {
        foreach (['preset', 'test', 'save', 'send-test', 'delete'] as $endpoint) {
            $response = $this->post('/api/email-account/' . $endpoint, ['_csrf' => $this->csrfToken()]);

            self::assertContains(
                $response->status,
                [302, 401],
                '/api/email-account/' . $endpoint . ' was reachable without a session'
            );
        }
    }

    public function testWriteEndpointsRejectARequestWithoutACsrfToken(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        $response = $this->post('/api/email-account/test', ['from_email' => 'ada@studio.test']);

        self::assertSame(419, $response->status, 'CSRF protection is not covering this endpoint');
    }

    public function testTheSettingsPageRendersTheFormWithoutAnyCredential(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        $response = $this->get('/settings/email');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="from_email"', $response->body);
        self::assertStringContainsString('name="smtp_host"', $response->body);
        self::assertStringContainsString('name="imap_host"', $response->body);
        // send_as_name and reply_to are prefilled from the user.
        self::assertStringContainsString('Ada Lovelace', $response->body);
        self::assertStringNotContainsString(self::APP_PASSWORD, $response->body);
    }

    public function testThePresetEndpointReturnsProviderSettings(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        $response = $this->postJson('/api/email-account/preset', [
            '_csrf' => $this->csrfToken(),
            'email' => 'someone@gmail.com',
        ]);

        self::assertSame(200, $response->status);

        $preset = json_decode($response->body, true)['preset'];
        self::assertSame('smtp.gmail.com', $preset['smtp_host']);
        self::assertSame('imap.gmail.com', $preset['imap_host']);
        self::assertSame('gmail', $preset['provider']);
        self::assertNotNull($preset['app_password_notice'], 'Gmail users need the warning before they fail');
    }

    public function testThePresetEndpointRejectsAnInvalidAddress(): void
    {
        $this->actingAs(['email' => 'ada@studio.test']);

        $response = $this->postJson('/api/email-account/preset', [
            '_csrf' => $this->csrfToken(),
            'email' => 'not-an-email',
        ]);

        self::assertSame(422, $response->status);
    }

    public function testSavingThroughTheEndpointStoresAnActiveAccount(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $server = $this->server(FakeMailServer::MODE_GOOD);

        $response = $this->postJson('/api/email-account/save', [
            '_csrf' => $this->csrfToken(),
        ] + $this->input($server));

        self::assertSame(200, $response->status, $response->body);

        $body = json_decode($response->body, true);
        self::assertTrue($body['saved']);
        self::assertStringNotContainsString(self::APP_PASSWORD, $response->body);

        $tenantId = $this->tenantIdFor((int) $user['id']);
        $account = EmailAccount::find($tenantId, (int) $body['account_id']);
        self::assertSame(EmailAccount::STATUS_ACTIVE, $account['status']);
    }

    public function testAFailedSaveReturns422AndWritesNothing(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $server = $this->server(FakeMailServer::MODE_BAD_PASSWORD);

        $input = $this->input($server);
        $input['smtp_password'] = 'wrong';
        $input['imap_password'] = 'wrong';

        $response = $this->postJson('/api/email-account/save', ['_csrf' => $this->csrfToken()] + $input);

        self::assertSame(422, $response->status);

        $body = json_decode($response->body, true);
        self::assertFalse($body['saved']);
        self::assertNotEmpty($body['smtp']['message']);

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM email_accounts WHERE tenant_id = ?');
        $count->execute([$this->tenantIdFor((int) $user['id'])]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testSaveValidatesBeforeOpeningAnyConnection(): void
    {
        $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);

        $response = $this->postJson('/api/email-account/save', [
            '_csrf' => $this->csrfToken(),
            'from_email' => 'ada@studio.test',
            'from_name' => 'Ada Lovelace',
            'smtp_host' => 'smtp.test',
            // imap_host deliberately omitted: IMAP is required, not optional.
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('IMAP', json_decode($response->body, true)['error']);
    }

    public function testDeletingDisconnectsTheAccount(): void
    {
        $user = $this->actingAs(['email' => 'ada@studio.test', 'name' => 'Ada Lovelace']);
        $server = $this->server(FakeMailServer::MODE_GOOD);

        $saved = json_decode(
            $this->postJson('/api/email-account/save', ['_csrf' => $this->csrfToken()] + $this->input($server))->body,
            true
        );

        $response = $this->postJson('/api/email-account/delete', [
            '_csrf' => $this->csrfToken(),
            'account_id' => $saved['account_id'],
        ]);

        self::assertSame(200, $response->status);
        self::assertNull(EmailAccount::find($this->tenantIdFor((int) $user['id']), (int) $saved['account_id']));
    }

    // -------------------------------------------------------------- helpers

    private function tenantIdFor(int $userId): int
    {
        $statement = Database::connection()->prepare('SELECT organization_id FROM users WHERE id = ?');
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }

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
