<?php

namespace Keel\App\Mail;

/**
 * One reminder, fully rendered and ready to hand to a transport.
 *
 * Deliberately transport-agnostic: it carries RFC822 threading headers as
 * plain values rather than as SMTP-specific plumbing, so a Gmail API transport
 * can map them onto that API's own threading fields without the scheduler or
 * the sender knowing which transport is in play.
 */
final class OutboundMessage
{
    public function __construct(
        public readonly string $toEmail,
        public readonly string $toName,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly ?string $replyTo,
        public readonly string $subject,
        public readonly string $textBody,
        public readonly string $htmlBody,
        /** The Message-ID this send should carry, minted before dispatch. */
        public readonly string $messageId,
        /** The root message of the thread, absent on the first send. */
        public readonly ?string $inReplyTo = null,
        /** Space-separated Message-IDs, oldest first, per RFC 5322. */
        public readonly ?string $references = null,
    ) {
    }

    /**
     * Headers a transport should set verbatim where its protocol allows it.
     *
     * @return array<string, string>
     */
    public function threadingHeaders(): array
    {
        $headers = ['Message-ID' => $this->messageId];

        if ($this->inReplyTo !== null && $this->inReplyTo !== '') {
            $headers['In-Reply-To'] = $this->inReplyTo;
        }

        if ($this->references !== null && $this->references !== '') {
            $headers['References'] = $this->references;
        }

        return $headers;
    }

    public function isFirstInThread(): bool
    {
        return $this->inReplyTo === null || $this->inReplyTo === '';
    }
}
