<?php

namespace Keel\App\Services;

use Keel\App\Models\EmailAccount;
use Keel\Core\Database;
use Throwable;

/**
 * Owns the lifecycle of the mailbox Duely sends from and polls.
 *
 * Two rules shape everything here:
 *
 *  1. Nothing is saved that has not just been proven to work. test() opens a
 *     real SMTP session and a real IMAP session; save() runs the same test and
 *     writes only if both pass, inside a transaction, so a half-working
 *     account can never reach the database.
 *  2. A plaintext credential exists only as a local variable. It is decrypted
 *     at the moment of use, never logged, never returned to the client, and
 *     never rendered into HTML — the form shows a mask instead.
 */
class MailAccountService
{
    /**
     * What the form renders in a password field that already has a stored value.
     * Submitting it unchanged means "keep the password you have".
     */
    public const MASKED_PLACEHOLDER = '••••••••••••';

    public function __construct(
        private readonly SmtpProbe $smtp = new SmtpProbe(),
        private readonly ImapClient $imap = new ImapClient(),
    ) {
    }

    // ------------------------------------------------------------ prefilling

    /**
     * Settings to prefill the form with for an address, plus any warning worth
     * showing before the user even attempts a connection.
     */
    public function presetFor(string $email): array
    {
        $preset = ProviderPresets::forEmail($email);
        $preset['app_password_notice'] = AppPasswordGuidance::preflightNotice($preset['provider']);

        return $preset;
    }

    /**
     * The saved account as the settings screen should see it: never any
     * ciphertext, never any secret, just whether one is on file.
     */
    public function formState(int $tenantId, array $user): array
    {
        $account = EmailAccount::defaultAccount($tenantId) ?? $this->firstAccount($tenantId);

        if ($account === null) {
            return $this->emptyFormState($user);
        }

        $safe = EmailAccount::redact($account);

        return [
            'exists' => true,
            'id' => (int) $safe['id'],
            'provider' => (string) $safe['provider'],
            'status' => (string) $safe['status'],
            'from_name' => (string) $safe['from_name'],
            'from_email' => (string) $safe['from_email'],
            'reply_to' => (string) ($safe['reply_to'] ?? ''),
            'smtp_host' => (string) ($safe['smtp_host'] ?? ''),
            'smtp_port' => (int) ($safe['smtp_port'] ?? 587),
            'smtp_encryption' => (string) $safe['smtp_encryption'],
            'smtp_username' => (string) ($safe['smtp_username'] ?? ''),
            'imap_host' => (string) ($safe['imap_host'] ?? ''),
            'imap_port' => (int) ($safe['imap_port'] ?? 993),
            'imap_encryption' => (string) $safe['imap_encryption'],
            'imap_username' => (string) ($safe['imap_username'] ?? ''),
            'imap_folder' => (string) ($safe['imap_folder'] ?? 'INBOX'),
            'last_verified_at' => $safe['last_verified_at'],
            'last_error' => $safe['last_error'],
            // Booleans only. The values themselves never leave the server.
            'has_smtp_password' => $account['smtp_password_encrypted'] !== null,
            'has_imap_password' => $account['imap_password_encrypted'] !== null,
            'masked_placeholder' => self::MASKED_PLACEHOLDER,
            'app_password_notice' => AppPasswordGuidance::preflightNotice((string) $safe['provider']),
        ];
    }

    // ----------------------------------------------------------- connection

    /**
     * Open a real SMTP session and a real IMAP session with the supplied
     * settings. Both must pass for the account to be considered usable.
     *
     * @return array{ok:bool, smtp:array, imap:array, guidance:?array}
     */
    public function test(int $tenantId, array $input): array
    {
        $settings = $this->normalise($input);
        $existing = $this->resolveExisting($tenantId, $input);

        $smtpPassword = $this->resolvePassword(
            $settings['smtp_password'],
            $existing,
            static fn (array $row): ?string => EmailAccount::smtpPassword($row)
        );
        $imapPassword = $this->resolvePassword(
            $settings['imap_password'],
            $existing,
            static fn (array $row): ?string => EmailAccount::imapPassword($row)
        );

        $smtpResult = $this->smtp->test(
            $settings['smtp_host'],
            $settings['smtp_port'],
            $settings['smtp_encryption'],
            $settings['smtp_username'],
            (string) $smtpPassword,
            $settings['provider']
        );

        $imapResult = $this->imap->test(
            $settings['imap_host'],
            $settings['imap_port'],
            $settings['imap_encryption'],
            $settings['imap_username'],
            (string) $imapPassword,
            $settings['imap_folder'],
            $settings['provider']
        );

        $guidance = null;

        if ($smtpResult->isAuthProblem() || $imapResult->isAuthProblem()) {
            $guidance = AppPasswordGuidance::forProvider($settings['provider']);
        }

        return [
            'ok' => $smtpResult->succeeded() && $imapResult->succeeded(),
            'smtp' => $smtpResult->toArray(),
            'imap' => $imapResult->toArray(),
            'guidance' => $guidance,
        ];
    }

    /**
     * Test, then persist only on success. Returns the same shape as test() plus
     * the saved account id, so one call drives the whole submit flow.
     *
     * @return array{ok:bool, smtp:array, imap:array, guidance:?array, account_id:?int, saved:bool}
     */
    public function save(int $tenantId, ?int $userId, array $input): array
    {
        $settings = $this->normalise($input);
        $existing = $this->resolveExisting($tenantId, $input);

        $result = $this->test($tenantId, $input);

        // No partial saves: a failing test writes nothing at all.
        if (!$result['ok']) {
            return $result + ['account_id' => $existing === null ? null : (int) $existing['id'], 'saved' => false];
        }

        $smtpPassword = $this->resolvePassword(
            $settings['smtp_password'],
            $existing,
            static fn (array $row): ?string => EmailAccount::smtpPassword($row)
        );
        $imapPassword = $this->resolvePassword(
            $settings['imap_password'],
            $existing,
            static fn (array $row): ?string => EmailAccount::imapPassword($row)
        );

        $attributes = [
            'user_id' => $userId,
            'provider' => $settings['provider'],
            'from_name' => $settings['from_name'],
            'from_email' => $settings['from_email'],
            'reply_to' => $settings['reply_to'],
            'smtp_host' => $settings['smtp_host'],
            'smtp_port' => $settings['smtp_port'],
            'smtp_encryption' => $settings['smtp_encryption'],
            'smtp_username' => $settings['smtp_username'],
            'smtp_password' => $smtpPassword,
            'imap_host' => $settings['imap_host'],
            'imap_port' => $settings['imap_port'],
            'imap_encryption' => $settings['imap_encryption'],
            'imap_username' => $settings['imap_username'],
            'imap_password' => $imapPassword,
            'imap_folder' => $settings['imap_folder'],
            'status' => EmailAccount::STATUS_ACTIVE,
            'last_verified_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ];

        $connection = Database::connection();
        $openedTransaction = !$connection->inTransaction();

        if ($openedTransaction) {
            $connection->beginTransaction();
        }

        try {
            if ($existing !== null) {
                $accountId = (int) $existing['id'];
                EmailAccount::update($tenantId, $accountId, $attributes);
            } else {
                $attributes['is_default'] = 1;
                $accountId = EmailAccount::create($tenantId, $attributes);
            }

            if ($openedTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if ($openedTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        EmailAccount::makeDefault($tenantId, $accountId);

        return $result + ['account_id' => $accountId, 'saved' => true];
    }

    public function delete(int $tenantId, int $accountId): bool
    {
        return EmailAccount::delete($tenantId, $accountId);
    }

    public function disable(int $tenantId, int $accountId): bool
    {
        return EmailAccount::update($tenantId, $accountId, [
            'status' => EmailAccount::STATUS_DISABLED,
        ]);
    }

    /**
     * Send a real message through the saved account, so the user sees proof in
     * their own inbox rather than only a green tick.
     */
    public function sendTestMessage(int $tenantId, int $accountId, string $toEmail, string $toName = ''): ConnectionDiagnosis
    {
        $account = EmailAccount::find($tenantId, $accountId);

        if ($account === null) {
            return ConnectionDiagnosis::failure('smtp', ConnectionDiagnosis::NOT_CONFIGURED, 'No saved email account to send from.');
        }

        $password = EmailAccount::smtpPassword($account);

        if ($password === null) {
            return ConnectionDiagnosis::failure('smtp', ConnectionDiagnosis::NOT_CONFIGURED, 'This account has no saved sending password.');
        }

        $fromName = (string) $account['from_name'];
        $subject = 'Duely is connected to your inbox';
        $html = $this->testMessageHtml($fromName, (string) $account['from_email']);

        return $this->smtp->send(
            [
                'host' => (string) $account['smtp_host'],
                'port' => (int) $account['smtp_port'],
                'encryption' => (string) $account['smtp_encryption'],
                'username' => (string) $account['smtp_username'],
                'password' => $password,
                'provider' => (string) $account['provider'],
            ],
            (string) $account['from_email'],
            $fromName,
            $account['reply_to'] !== null && $account['reply_to'] !== '' ? (string) $account['reply_to'] : null,
            $toEmail,
            $toName !== '' ? $toName : $fromName,
            $subject,
            $html,
            $this->testMessageText($fromName)
        );
    }

    /**
     * Flip an account to needs_reauth after a send or poll fails on auth. The
     * banner in settings reads from this.
     */
    public function markNeedsReauth(int $tenantId, int $accountId, ConnectionDiagnosis $diagnosis): void
    {
        EmailAccount::markNeedsReauth($tenantId, $accountId, $diagnosis->message);
    }

    // -------------------------------------------------------------- internals

    /**
     * Coerce raw form input into validated settings. Ports are clamped, hosts
     * trimmed of scheme and whitespace, and unknown enum values fall back to a
     * safe default rather than reaching the database.
     */
    private function normalise(array $input): array
    {
        $fromEmail = strtolower(trim((string) ($input['from_email'] ?? '')));
        $preset = ProviderPresets::forEmail($fromEmail);

        $smtpUsername = trim((string) ($input['smtp_username'] ?? ''));
        $imapUsername = trim((string) ($input['imap_username'] ?? ''));

        return [
            'provider' => $this->allowed(
                (string) ($input['provider'] ?? $preset['provider']),
                [ProviderPresets::PROVIDER_SMTP, ProviderPresets::PROVIDER_GMAIL, ProviderPresets::PROVIDER_OUTLOOK],
                $preset['provider']
            ),
            'from_name' => trim((string) ($input['from_name'] ?? '')),
            'from_email' => $fromEmail,
            'reply_to' => strtolower(trim((string) ($input['reply_to'] ?? ''))) ?: null,

            'smtp_host' => $this->host((string) ($input['smtp_host'] ?? '')),
            'smtp_port' => $this->port($input['smtp_port'] ?? null, 587),
            'smtp_encryption' => $this->allowed((string) ($input['smtp_encryption'] ?? 'tls'), ['none', 'tls', 'ssl'], 'tls'),
            'smtp_username' => $smtpUsername !== '' ? $smtpUsername : $fromEmail,
            'smtp_password' => (string) ($input['smtp_password'] ?? ''),

            'imap_host' => $this->host((string) ($input['imap_host'] ?? '')),
            'imap_port' => $this->port($input['imap_port'] ?? null, 993),
            'imap_encryption' => $this->allowed((string) ($input['imap_encryption'] ?? 'ssl'), ['none', 'tls', 'ssl'], 'ssl'),
            'imap_username' => $imapUsername !== '' ? $imapUsername : $fromEmail,
            'imap_password' => (string) ($input['imap_password'] ?? ''),
            'imap_folder' => trim((string) ($input['imap_folder'] ?? 'INBOX')) ?: 'INBOX',
        ];
    }

    /**
     * A submitted password that is empty or still the mask means "keep the one
     * already stored", so an edit to the reply-to address does not silently
     * wipe the credential.
     */
    private function resolvePassword(string $submitted, ?array $existing, callable $reader): ?string
    {
        if ($submitted !== '' && $submitted !== self::MASKED_PLACEHOLDER) {
            return $submitted;
        }

        if ($existing === null) {
            return null;
        }

        return $reader($existing);
    }

    private function resolveExisting(int $tenantId, array $input): ?array
    {
        $accountId = (int) ($input['account_id'] ?? 0);

        if ($accountId > 0) {
            return EmailAccount::find($tenantId, $accountId);
        }

        $fromEmail = strtolower(trim((string) ($input['from_email'] ?? '')));

        if ($fromEmail !== '') {
            $byEmail = EmailAccount::findByEmail($tenantId, $fromEmail);

            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        return EmailAccount::defaultAccount($tenantId);
    }

    private function firstAccount(int $tenantId): ?array
    {
        $accounts = EmailAccount::all($tenantId, 1);

        return $accounts[0] ?? null;
    }

    private function emptyFormState(array $user): array
    {
        $email = (string) ($user['email'] ?? '');
        $name = trim((string) ($user['name'] ?? ''));
        $preset = ProviderPresets::forEmail($email);

        // send_as_name and reply_to default to the user's own name and address.
        return [
            'exists' => false,
            'id' => null,
            'provider' => $preset['provider'],
            'status' => EmailAccount::STATUS_UNVERIFIED,
            'from_name' => $name !== '' ? $name : $email,
            'from_email' => $email,
            'reply_to' => $email,
            'smtp_host' => $preset['smtp_host'],
            'smtp_port' => $preset['smtp_port'],
            'smtp_encryption' => $preset['smtp_encryption'],
            'smtp_username' => $email,
            'imap_host' => $preset['imap_host'],
            'imap_port' => $preset['imap_port'],
            'imap_encryption' => $preset['imap_encryption'],
            'imap_username' => $email,
            'imap_folder' => 'INBOX',
            'last_verified_at' => null,
            'last_error' => null,
            'has_smtp_password' => false,
            'has_imap_password' => false,
            'masked_placeholder' => self::MASKED_PLACEHOLDER,
            'app_password_notice' => AppPasswordGuidance::preflightNotice($preset['provider']),
        ];
    }

    private function host(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^[a-z]+://#i', '', $value) ?? $value;

        return rtrim(trim($value), '/');
    }

    private function port(mixed $value, int $default): int
    {
        $port = (int) $value;

        return ($port >= 1 && $port <= 65535) ? $port : $default;
    }

    /**
     * @param string[] $allowed
     */
    private function allowed(string $value, array $allowed, string $default): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function testMessageHtml(string $fromName, string $fromEmail): string
    {
        $name = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#0A0A0A;">'
            . '<p>Your mailbox is connected.</p>'
            . '<p>Duely will send invoice reminders from <strong>' . $name . ' &lt;' . $email . '&gt;</strong>, '
            . 'and will watch this inbox for replies so a chase stops the moment your client answers.</p>'
            . '<p style="color:#525252;font-size:13px;">This is a one-off test message. Nothing has been sent to your clients.</p>'
            . '</div>';
    }

    private function testMessageText(string $fromName): string
    {
        return "Your mailbox is connected.\n\n"
            . 'Duely will send invoice reminders as ' . $fromName . ', and will watch this inbox '
            . "for replies so a chase stops the moment your client answers.\n\n"
            . 'This is a one-off test message. Nothing has been sent to your clients.';
    }
}
