<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Mail\MailTransport;
use Keel\App\Mail\OutboundMessage;
use Keel\App\Mail\SendResult;

/**
 * A transport that records instead of sending.
 *
 * Its existence is also the point: the cadence engine takes MailTransport, so
 * a completely different implementation drops in without the scheduler or the
 * sender changing. If a future GmailApiTransport needs edits to either of
 * those, the abstraction has failed.
 */
class RecordingTransport implements MailTransport
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    /** Result to return for the next send; success by default. */
    public ?SendResult $nextResult = null;

    /** Called before recording, so a test can simulate a mid-send failure. */
    public $onSend = null;

    public function name(): string
    {
        return 'recording';
    }

    public function supports(array $account): bool
    {
        return true;
    }

    public function send(array $account, OutboundMessage $message): SendResult
    {
        if ($this->onSend !== null) {
            ($this->onSend)($message);
        }

        $this->sent[] = [
            'to' => $message->toEmail,
            'from' => $message->fromEmail,
            'subject' => $message->subject,
            'text' => $message->textBody,
            'html' => $message->htmlBody,
            'headers' => $message->threadingHeaders(),
            'message_id' => $message->messageId,
            'in_reply_to' => $message->inReplyTo,
            'references' => $message->references,
        ];

        $result = $this->nextResult ?? SendResult::success($message->messageId);
        $this->nextResult = null;

        return $result;
    }

    /**
     * Everything sent to one recipient, so assertions survive other chases
     * becoming due in the same run.
     *
     * @return array<int, array<string, mixed>>
     */
    public function to(string $email): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn (array $message): bool => $message['to'] === $email
        ));
    }
}
