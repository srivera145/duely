<?php

namespace Keel\App\Services;

use DateTimeImmutable;

/**
 * One inbound message, parsed down to the few things Duely needs.
 *
 * Deliberately narrow: headers used for matching and classification, plus a
 * short extract of the body. The full message is never retained — Duely is
 * reading someone's personal inbox, and the least it can do is keep almost
 * none of it.
 */
final class InboundMessage
{
    private const SNIPPET_LENGTH = 300;

    /**
     * @param array<string, string[]> $headers lowercased name => values
     */
    private function __construct(
        public readonly int $uid,
        public readonly array $headers,
        public readonly string $fromEmail,
        public readonly string $subject,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        /** @var string[] every Message-ID in the References chain, newest last */
        public readonly array $references,
        public readonly ?string $threadId,
        public readonly ?DateTimeImmutable $receivedAt,
        public readonly string $snippet,
        public readonly string $rawHeaders,
    ) {
    }

    public static function parse(int $uid, string $rawHeaders, string $rawBody = ''): self
    {
        $headers = self::parseHeaders($rawHeaders);

        $references = self::extractMessageIds(self::header($headers, 'references') ?? '');
        $inReplyTo = self::firstMessageId(self::header($headers, 'in-reply-to') ?? '');

        return new self(
            uid: $uid,
            headers: $headers,
            fromEmail: self::extractAddress(self::header($headers, 'from') ?? ''),
            subject: self::decodeWords(self::header($headers, 'subject') ?? ''),
            messageId: self::firstMessageId(self::header($headers, 'message-id') ?? ''),
            inReplyTo: $inReplyTo,
            references: $references,
            // Gmail exposes its own conversation id; other providers do not.
            threadId: self::header($headers, 'x-gm-thrid')
                ?? self::header($headers, 'thread-index')
                ?? self::header($headers, 'x-thread-id'),
            receivedAt: self::parseDate(self::header($headers, 'date')),
            snippet: self::buildSnippet($rawBody),
            rawHeaders: $rawHeaders,
        );
    }

    /**
     * Every Message-ID this message claims to be answering, most specific first.
     *
     * In-Reply-To is the direct parent; References walks back up the thread,
     * so the newest entries are the most likely match.
     *
     * @return string[]
     */
    public function threadCandidates(): array
    {
        $candidates = [];

        if ($this->inReplyTo !== null) {
            $candidates[] = $this->inReplyTo;
        }

        foreach (array_reverse($this->references) as $reference) {
            $candidates[] = $reference;
        }

        return array_values(array_unique($candidates));
    }

    public function first(string $name): ?string
    {
        return self::headerValue($this->headers, $name);
    }

    /**
     * @return string[]
     */
    public function all(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    // ------------------------------------------------------------- parsing

    /**
     * @return array<string, string[]>
     */
    private static function parseHeaders(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Unfold: a header value continued on the next line starts with space.
        $raw = preg_replace('/\n[ \t]+/', ' ', $raw) ?? $raw;

        $headers = [];

        foreach (explode("\n", $raw) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));

            if ($name === '') {
                continue;
            }

            $headers[$name][] = trim($value);
        }

        return $headers;
    }

    private static function header(array $headers, string $name): ?string
    {
        return self::headerValue($headers, $name);
    }

    private static function headerValue(array $headers, string $name): ?string
    {
        $values = $headers[strtolower($name)] ?? [];

        return $values === [] ? null : (string) $values[0];
    }

    /**
     * Pull the bare address out of `Dana Whitfield <dana@example.com>`.
     */
    private static function extractAddress(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            return strtolower(trim($matches[1]));
        }

        if (preg_match('/([^\s<>,;]+@[^\s<>,;]+)/', $value, $matches) === 1) {
            return strtolower(trim($matches[1]));
        }

        return '';
    }

    /**
     * @return string[]
     */
    private static function extractMessageIds(string $value): array
    {
        preg_match_all('/<[^<>\s]+>/', $value, $matches);

        return array_values(array_unique($matches[0]));
    }

    private static function firstMessageId(string $value): ?string
    {
        $ids = self::extractMessageIds($value);

        return $ids === [] ? null : $ids[0];
    }

    /**
     * Decode RFC 2047 encoded-words so an out-of-office subject in UTF-8 is
     * still recognisable to the classifier.
     */
    private static function decodeWords(string $value): string
    {
        if ($value === '' || !str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($decoded) && $decoded !== '' ? $decoded : $value;
    }

    private static function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(Clock::utc());
        } catch (\Exception) {
            // A malformed Date header is not worth dropping the message over.
            return null;
        }
    }

    /**
     * The first ~300 readable characters, so the user can see what was said
     * without Duely keeping the message.
     */
    private static function buildSnippet(string $rawBody): string
    {
        if (trim($rawBody) === '') {
            return '';
        }

        $body = str_replace(["\r\n", "\r"], "\n", $rawBody);

        // A MIME body starts with part headers; skip to the first blank line.
        if (preg_match('/^(content-type|content-transfer-encoding|mime-version):/im', $body) === 1) {
            $split = preg_split('/\n\s*\n/', $body, 2);

            if (is_array($split) && count($split) === 2) {
                $body = $split[1];
            }
        }

        if (stripos($rawBody, 'quoted-printable') !== false) {
            $body = quoted_printable_decode($body);
        }

        $body = strip_tags($body);

        // Drop quoted history so the snippet shows what the client wrote, not
        // a replay of our own reminder.
        $lines = [];

        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '>')) {
                continue;
            }

            if (preg_match('/^(on .+ wrote:|-{2,} ?original message ?-{2,}|from: )/i', $trimmed) === 1) {
                break;
            }

            $lines[] = $trimmed;
        }

        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? '');

        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return mb_substr($text, 0, self::SNIPPET_LENGTH);
    }
}
