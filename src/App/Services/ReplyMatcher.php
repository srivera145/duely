<?php

namespace Keel\App\Services;

use DateTimeImmutable;
use Keel\App\Models\ChaseMessage;
use Keel\App\Models\ReplyEvent;
use Keel\Core\Database;

/**
 * Works out which chase an inbound message belongs to, and what kind of
 * message it is.
 *
 * This class exists to prevent the product's worst failure: a client replies
 * "cheque went out Friday" and Duely sends a Final Notice anyway. Everything
 * here is biased towards noticing a reply.
 *
 * Matching runs in strict priority order, and never on subject line alone —
 * subjects are edited, translated, forwarded and reused, and matching on them
 * would attach a stranger's email to a client's chase.
 */
class ReplyMatcher
{
    public const MATCH_MESSAGE_ID = 'message_id';
    public const MATCH_THREAD_ID = 'thread_id';
    public const MATCH_SENDER = 'sender';
    public const MATCH_NONE = 'none';

    /** How far back a bare sender-address match is willing to look. */
    private const SENDER_WINDOW_DAYS = 60;

    /**
     * Headers that mean "a machine sent this", so it must not pause anything.
     *
     * An out-of-office is not a person saying they will pay. Treating one as a
     * reply would silently stop chasing an invoice for a fortnight.
     */
    private const AUTO_REPLY_HEADERS = [
        'x-autoreply',
        'x-autorespond',
        'x-auto-response-suppress',
        'x-mailer-daemon-recipients',
    ];

    private const OUT_OF_OFFICE_PATTERNS = [
        '/\bout of (the )?office\b/i',
        '/\bauto(matic)?[- ]?repl(y|ies)\b/i',
        '/\bautoresponse\b/i',
        '/\baway from (my )?(the )?(desk|office|email)\b/i',
        '/\bon (annual |parental |maternity |paternity )?leave\b/i',
        '/\bon (holiday|vacation)\b/i',
        '/\bno longer (with|at)\b/i',
        '/\babwesenheitsnotiz\b/i',
        '/\bréponse automatique\b/iu',
        '/fuera de la oficina/iu',
        '/assenza/iu',
    ];

    private const BOUNCE_SENDERS = [
        'mailer-daemon@',
        'postmaster@',
        'mail-daemon@',
        'no-reply@',
        'bounce',
    ];

    /**
     * Identify an inbound message.
     *
     * @return array{
     *     chase_id:?int, chase_message_id:?int, matched_by:string,
     *     type:string, is_hard_bounce:bool, reason:string
     * }
     */
    public function match(int $tenantId, InboundMessage $message, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();

        $type = $this->classify($message);
        $isHardBounce = $type === ReplyEvent::TYPE_BOUNCE && $this->isHardBounce($message);

        // 1. The message says which of our messages it answers. Strongest signal.
        $byMessageId = $this->matchByMessageId($tenantId, $message);

        if ($byMessageId !== null) {
            return $this->result($byMessageId['chase_id'], $byMessageId['chase_message_id'], self::MATCH_MESSAGE_ID, $type, $isHardBounce,
                'Matched on a Message-ID we sent.');
        }

        // 2. A provider-native conversation id, where one exists.
        $byThread = $this->matchByThreadId($tenantId, $message);

        if ($byThread !== null) {
            return $this->result($byThread, null, self::MATCH_THREAD_ID, $type, $isHardBounce,
                'Matched on the provider thread id.');
        }

        // 3. A bounce quotes the original message rather than replying to it,
        //    so the failed recipient is often only recoverable from the body.
        if ($type === ReplyEvent::TYPE_BOUNCE) {
            $byBounce = $this->matchBounceRecipient($tenantId, $message, $now);

            if ($byBounce !== null) {
                return $this->result($byBounce['chase_id'], $byBounce['chase_message_id'], self::MATCH_SENDER, $type, $isHardBounce,
                    'Matched the address the bounce reports as failed.');
            }
        }

        // 4. Last resort: this address has a live chase and we wrote to it
        //    recently. Bounded by a window so an unrelated email months later
        //    does not stop a fresh chase.
        $bySender = $this->matchBySender($tenantId, $message, $now);

        if ($bySender !== null) {
            return $this->result($bySender, null, self::MATCH_SENDER, $type, $isHardBounce,
                'Matched the sender against a client with an active chase.');
        }

        return $this->result(null, null, self::MATCH_NONE, $type, $isHardBounce, 'No chase matched this message.');
    }

    // ------------------------------------------------------- classification

    /**
     * What kind of inbound message is this?
     *
     * Bounce is checked before auto-reply because a bounce often carries
     * auto-submitted headers too, and the two need different handling.
     */
    public function classify(InboundMessage $message): string
    {
        if ($this->looksLikeBounce($message)) {
            return ReplyEvent::TYPE_BOUNCE;
        }

        if ($this->looksAutomated($message)) {
            return ReplyEvent::TYPE_AUTO_REPLY;
        }

        if ($this->looksLikeComplaint($message)) {
            return ReplyEvent::TYPE_COMPLAINT;
        }

        if ($message->fromEmail === '') {
            return ReplyEvent::TYPE_UNKNOWN;
        }

        return ReplyEvent::TYPE_REPLY;
    }

    public function looksLikeBounce(InboundMessage $message): bool
    {
        $contentType = strtolower((string) $message->first('content-type'));

        // The RFC 3462 delivery status notification.
        if (str_contains($contentType, 'multipart/report')
            && str_contains($contentType, 'delivery-status')) {
            return true;
        }

        if ($message->first('x-failed-recipients') !== null) {
            return true;
        }

        $from = strtolower($message->fromEmail);

        foreach (self::BOUNCE_SENDERS as $needle) {
            if ($from !== '' && str_contains($from, $needle)) {
                // "no-reply@" alone is a weak signal — plenty of legitimate
                // senders use it — so require corroboration from the subject.
                if ($needle === 'no-reply@' || $needle === 'bounce') {
                    return $this->subjectSuggestsFailure($message);
                }

                return true;
            }
        }

        // An empty envelope sender is the classic bounce signature.
        if (trim((string) $message->first('return-path')) === '<>') {
            return true;
        }

        return false;
    }

    public function looksAutomated(InboundMessage $message): bool
    {
        // RFC 3834. "auto-generated" and "auto-replied" are machines;
        // "no" explicitly means a human sent it.
        $autoSubmitted = strtolower(trim((string) $message->first('auto-submitted')));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        foreach (self::AUTO_REPLY_HEADERS as $header) {
            if ($message->first($header) !== null) {
                return true;
            }
        }

        $precedence = strtolower(trim((string) $message->first('precedence')));

        if (in_array($precedence, ['auto_reply', 'auto-reply', 'bulk', 'junk', 'list'], true)) {
            return true;
        }

        // Microsoft and Google both set this on vacation responders.
        if (strtolower(trim((string) $message->first('x-auto-response-suppress'))) !== '') {
            return true;
        }

        foreach (self::OUT_OF_OFFICE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message->subject) === 1) {
                return true;
            }
        }

        return false;
    }

    public function looksLikeComplaint(InboundMessage $message): bool
    {
        $contentType = strtolower((string) $message->first('content-type'));

        return str_contains($contentType, 'report-type=feedback-report');
    }

    /**
     * Is this a permanent failure?
     *
     * Only a hard bounce should stop a chase and mark an address invalid. A
     * 4.x.x is a full mailbox or a temporary outage, and giving up on it would
     * abandon a perfectly good client.
     */
    public function isHardBounce(InboundMessage $message): bool
    {
        $haystack = $message->rawHeaders . ' ' . $message->snippet;

        // RFC 3463 enhanced status codes: 5.x.x is permanent.
        if (preg_match('/\bstatus:\s*5\.\d+\.\d+/i', $haystack) === 1) {
            return true;
        }

        if (preg_match('/\bstatus:\s*4\.\d+\.\d+/i', $haystack) === 1) {
            return false;
        }

        // Fall back to the wording servers use for a permanent failure.
        $permanent = [
            '/user unknown/i',
            '/no such user/i',
            '/does not exist/i',
            '/unknown recipient/i',
            '/recipient address rejected/i',
            '/mailbox unavailable/i',
            '/address rejected/i',
            '/permanent (failure|error)/i',
            '/550[- ]/',
            '/551[- ]/',
            '/553[- ]/',
        ];

        foreach ($permanent as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------ matching

    /**
     * @return array{chase_id:int, chase_message_id:int}|null
     */
    private function matchByMessageId(int $tenantId, InboundMessage $message): ?array
    {
        foreach ($message->threadCandidates() as $candidate) {
            $sql = 'SELECT id, chase_id FROM chase_messages
                    WHERE tenant_id = ? AND rfc_message_id = ?
                    LIMIT 1';

            $row = $this->query($sql, [$tenantId, $candidate]);

            if ($row !== null) {
                return ['chase_id' => (int) $row['chase_id'], 'chase_message_id' => (int) $row['id']];
            }
        }

        return null;
    }

    private function matchByThreadId(int $tenantId, InboundMessage $message): ?int
    {
        $candidates = array_filter([$message->threadId, ...$message->threadCandidates()]);

        foreach ($candidates as $candidate) {
            $sql = 'SELECT id FROM chases
                    WHERE tenant_id = ? AND (thread_id = ? OR root_message_id = ?)
                    LIMIT 1';

            $row = $this->query($sql, [$tenantId, $candidate, $candidate]);

            if ($row !== null) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * A bounce names the address that failed, usually in X-Failed-Recipients
     * or in the quoted delivery-status part. Resolve that back to a chase.
     *
     * @return array{chase_id:int, chase_message_id:?int}|null
     */
    private function matchBounceRecipient(int $tenantId, InboundMessage $message, DateTimeImmutable $now): ?array
    {
        $addresses = [];

        $failed = $message->first('x-failed-recipients');

        if ($failed !== null) {
            foreach (explode(',', $failed) as $address) {
                $addresses[] = strtolower(trim($address));
            }
        }

        // The original Message-ID is normally quoted in the report body.
        $haystack = $message->rawHeaders . ' ' . $message->snippet;

        if (preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/', $haystack, $matches) === 1 || $matches[0] !== []) {
            foreach ($matches[0] as $address) {
                $addresses[] = strtolower($address);
            }
        }

        $addresses = array_values(array_unique(array_filter($addresses)));

        foreach ($addresses as $address) {
            $chase = $this->activeChaseForAddress($tenantId, $address, $now);

            if ($chase !== null) {
                return ['chase_id' => $chase, 'chase_message_id' => null];
            }
        }

        return null;
    }

    private function matchBySender(int $tenantId, InboundMessage $message, DateTimeImmutable $now): ?int
    {
        if ($message->fromEmail === '') {
            return null;
        }

        return $this->activeChaseForAddress($tenantId, $message->fromEmail, $now);
    }

    /**
     * The most recent live chase against a client with this email address,
     * provided we actually wrote to them inside the window.
     */
    private function activeChaseForAddress(int $tenantId, string $address, DateTimeImmutable $now): ?int
    {
        $since = Clock::toDatabase($now->modify('-' . self::SENDER_WINDOW_DAYS . ' days'));

        $sql = 'SELECT ch.id
                FROM chases ch
                INNER JOIN invoices i ON i.id = ch.invoice_id AND i.tenant_id = ch.tenant_id
                INNER JOIN clients c ON c.id = i.client_id AND c.tenant_id = ch.tenant_id
                INNER JOIN chase_messages m ON m.chase_id = ch.id AND m.tenant_id = ch.tenant_id
                WHERE ch.tenant_id = ?
                  AND c.email = ?
                  AND ch.status IN (?, ?, ?)
                  AND m.status = ?
                  AND m.sent_at IS NOT NULL
                  AND m.sent_at >= ?
                ORDER BY m.sent_at DESC, ch.id DESC
                LIMIT 1';

        $row = $this->query($sql, [
            $tenantId,
            strtolower(trim($address)),
            \Keel\App\Models\Chase::STATUS_SCHEDULED,
            \Keel\App\Models\Chase::STATUS_ACTIVE,
            \Keel\App\Models\Chase::STATUS_PAUSED,
            ChaseMessage::STATUS_SENT,
            $since,
        ]);

        return $row === null ? null : (int) $row['id'];
    }

    // ------------------------------------------------------------ internals

    private function subjectSuggestsFailure(InboundMessage $message): bool
    {
        return preg_match(
            '/\b(undeliverable|delivery (has )?failed|delivery status notification|returned mail|mail delivery)\b/i',
            $message->subject
        ) === 1;
    }

    private function query(string $sql, array $bindings): ?array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetch() ?: null;
    }

    private function result(?int $chaseId, ?int $chaseMessageId, string $matchedBy, string $type, bool $isHardBounce, string $reason): array
    {
        return [
            'chase_id' => $chaseId,
            'chase_message_id' => $chaseMessageId,
            'matched_by' => $chaseId === null ? self::MATCH_NONE : $matchedBy,
            'type' => $type,
            'is_hard_bounce' => $isHardBounce,
            'reason' => $reason,
        ];
    }
}
