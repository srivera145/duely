<?php

namespace Keel\App\Mail;

use Keel\App\Services\ConnectionDiagnosis;

/**
 * What a transport reports back after attempting one send.
 *
 * The distinction that matters is between a failure worth retrying (the server
 * was busy, the network blipped) and one that is not (the password is wrong).
 * Retrying an authentication failure thirty times is how a consumer mailbox
 * gets locked, so `retryable` is part of the contract rather than a guess the
 * sender makes from an error string.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $messageId,
        public readonly string $message,
        public readonly string $code,
        public readonly bool $retryable,
        public readonly bool $needsReauth,
        public readonly ?string $detail = null,
    ) {
    }

    public static function success(string $messageId, string $message = 'Message accepted for delivery.'): self
    {
        return new self(true, $messageId, $message, ConnectionDiagnosis::OK, false, false);
    }

    /**
     * A transient failure: try again later.
     */
    public static function transientFailure(string $message, string $code = ConnectionDiagnosis::UNKNOWN, ?string $detail = null): self
    {
        return new self(false, null, $message, $code, true, false, $detail);
    }

    /**
     * A permanent failure: no amount of retrying will help.
     */
    public static function permanentFailure(string $message, string $code = ConnectionDiagnosis::UNKNOWN, ?string $detail = null): self
    {
        return new self(false, null, $message, $code, false, false, $detail);
    }

    /**
     * The mailbox will not accept us any more; the user has to reconnect it.
     */
    public static function authFailure(string $message, ?string $detail = null): self
    {
        return new self(false, null, $message, ConnectionDiagnosis::AUTH_FAILED, false, true, $detail);
    }

    /**
     * Build from the diagnosis the SMTP probe already knows how to produce, so
     * connection errors read the same wherever they surface.
     */
    public static function fromDiagnosis(ConnectionDiagnosis $diagnosis, string $messageId): self
    {
        if ($diagnosis->succeeded()) {
            return self::success($messageId, $diagnosis->message);
        }

        if ($diagnosis->isAuthProblem()) {
            return self::authFailure($diagnosis->message, $diagnosis->detail);
        }

        $transient = in_array($diagnosis->code, [
            ConnectionDiagnosis::RATE_LIMITED,
            ConnectionDiagnosis::HOST_UNREACHABLE,
            ConnectionDiagnosis::PORT_BLOCKED,
            ConnectionDiagnosis::TLS_FAILED,
            ConnectionDiagnosis::UNKNOWN,
        ], true);

        return $transient
            ? self::transientFailure($diagnosis->message, $diagnosis->code, $diagnosis->detail)
            : self::permanentFailure($diagnosis->message, $diagnosis->code, $diagnosis->detail);
    }

    public function toArray(): array
    {
        return [
            'sent' => $this->sent,
            'message_id' => $this->messageId,
            'message' => $this->message,
            'code' => $this->code,
            'retryable' => $this->retryable,
            'needs_reauth' => $this->needsReauth,
        ];
    }
}
