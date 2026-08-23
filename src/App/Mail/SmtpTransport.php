<?php

namespace Keel\App\Mail;

use Keel\App\Models\EmailAccount;
use Keel\App\Services\ConnectionDiagnosis;
use Keel\App\Services\CryptoException;
use Keel\App\Services\SmtpProbe;

/**
 * Sends through the user's own SMTP server using the credentials they
 * connected in settings.
 *
 * The password is decrypted here, held in a local variable for the duration of
 * one send, and never logged or returned.
 */
class SmtpTransport implements MailTransport
{
    public function __construct(private readonly SmtpProbe $probe = new SmtpProbe())
    {
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function supports(array $account): bool
    {
        return trim((string) ($account['smtp_host'] ?? '')) !== ''
            && ($account['smtp_password_encrypted'] ?? null) !== null;
    }

    public function send(array $account, OutboundMessage $message): SendResult
    {
        if (!$this->supports($account)) {
            return SendResult::permanentFailure(
                'This mailbox has no sending server configured.',
                ConnectionDiagnosis::NOT_CONFIGURED
            );
        }

        try {
            $password = EmailAccount::smtpPassword($account);
        } catch (CryptoException $exception) {
            // The stored credential cannot be read — usually a changed
            // APP_ENCRYPTION_KEY. Retrying will not fix it, and the user has to
            // reconnect the mailbox.
            return SendResult::authFailure(
                'The saved password for this mailbox could not be read. Reconnect it in settings.',
                $exception->getMessage()
            );
        }

        if ($password === null || $password === '') {
            return SendResult::authFailure('This mailbox has no saved sending password.');
        }

        $diagnosis = $this->probe->send(
            [
                'host' => (string) $account['smtp_host'],
                'port' => (int) $account['smtp_port'],
                'encryption' => (string) $account['smtp_encryption'],
                'username' => (string) $account['smtp_username'],
                'password' => $password,
                'provider' => (string) $account['provider'],
            ],
            (string) $account['from_email'],
            (string) $account['from_name'],
            $this->replyTo($account, $message),
            $message->toEmail,
            $message->toName,
            $message->subject,
            $message->htmlBody,
            $message->textBody,
            // SMTP carries the threading headers verbatim, which is what makes
            // the follow-ups collapse into one conversation in the client's
            // mail app rather than arriving as three unrelated emails.
            $message->threadingHeaders()
        );

        return SendResult::fromDiagnosis($diagnosis, $message->messageId);
    }

    /**
     * The message's own reply-to wins; otherwise the mailbox's configured one.
     */
    private function replyTo(array $account, OutboundMessage $message): ?string
    {
        if ($message->replyTo !== null && $message->replyTo !== '') {
            return $message->replyTo;
        }

        $accountReplyTo = trim((string) ($account['reply_to'] ?? ''));

        return $accountReplyTo !== '' ? $accountReplyTo : null;
    }
}
