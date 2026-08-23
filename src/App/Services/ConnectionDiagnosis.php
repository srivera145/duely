<?php

namespace Keel\App\Services;

/**
 * The outcome of one live connection attempt, in a form the UI can act on.
 *
 * Every failure carries a machine `code` and a sentence written for the person
 * who typed the password — never a stack trace, an exception class, or a raw
 * server reply. `$detail` holds the scrubbed protocol reply for support, and is
 * only ever shown behind a disclosure, never logged with credentials attached.
 */
class ConnectionDiagnosis
{
    public const OK = 'ok';
    public const AUTH_FAILED = 'auth_failed';
    public const APP_PASSWORD_REQUIRED = 'app_password_required';
    public const HOST_UNREACHABLE = 'host_unreachable';
    public const PORT_BLOCKED = 'port_blocked';
    public const TLS_FAILED = 'tls_failed';
    public const MAILBOX_MISSING = 'mailbox_missing';
    public const RATE_LIMITED = 'rate_limited';
    public const NOT_CONFIGURED = 'not_configured';
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $channel,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $detail = null,
        public readonly ?string $hint = null,
    ) {
    }

    public static function ok(string $channel, string $message): self
    {
        return new self($channel, self::OK, $message);
    }

    public static function failure(
        string $channel,
        string $code,
        string $message,
        ?string $detail = null,
        ?string $hint = null
    ): self {
        return new self($channel, $code, $message, $detail, $hint);
    }

    public function succeeded(): bool
    {
        return $this->code === self::OK;
    }

    public function isAuthProblem(): bool
    {
        return in_array($this->code, [self::AUTH_FAILED, self::APP_PASSWORD_REQUIRED], true);
    }

    /**
     * @return array{channel:string, ok:bool, code:string, message:string, detail:?string, hint:?string}
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'ok' => $this->succeeded(),
            'code' => $this->code,
            'message' => $this->message,
            'detail' => $this->detail,
            'hint' => $this->hint,
        ];
    }

    /**
     * Classify a socket-level failure. Distinguishing "we cannot find this host"
     * from "we found it but nothing answered on that port" is the difference
     * between the user fixing a typo and the user calling their host about a
     * firewall, so the two never share a message.
     */
    public static function fromSocketError(string $channel, string $host, int $port, int $errno, string $errstr): self
    {
        $haystack = strtolower($errstr);

        $isDnsFailure = str_contains($haystack, 'getaddrinfo')
            || str_contains($haystack, 'name or service not known')
            || str_contains($haystack, 'no such host')
            || str_contains($haystack, 'nodename nor servname')
            || str_contains($haystack, 'temporary failure in name resolution')
            || str_contains($haystack, 'host not found');

        if ($isDnsFailure) {
            return self::failure(
                $channel,
                self::HOST_UNREACHABLE,
                'We could not find the server "' . $host . '". Check the hostname for a typo.',
                $errstr,
                'Your email provider publishes this as an incoming/outgoing server name.'
            );
        }

        $isRefused = str_contains($haystack, 'refused')
            || $errno === 111
            || $errno === 10061;

        $isTimeout = str_contains($haystack, 'timed out')
            || str_contains($haystack, 'timeout')
            || $errno === 110
            || $errno === 10060;

        $isUnreachable = str_contains($haystack, 'unreachable')
            || $errno === 113
            || $errno === 10051;

        if ($isRefused) {
            return self::failure(
                $channel,
                self::PORT_BLOCKED,
                'The server "' . $host . '" refused the connection on port ' . $port . '.',
                $errstr,
                'The port is usually right but blocked. Try the alternate port, or ask your host to open ' . $port . '.'
            );
        }

        if ($isTimeout) {
            return self::failure(
                $channel,
                self::PORT_BLOCKED,
                'Port ' . $port . ' on "' . $host . '" did not respond in time. It is most likely blocked by a firewall.',
                $errstr,
                'Many home and office networks block outbound mail ports. Try the alternate port for this provider.'
            );
        }

        if ($isUnreachable) {
            return self::failure(
                $channel,
                self::HOST_UNREACHABLE,
                'We could not reach "' . $host . '" at all. The server may be down, or the network is blocking it.',
                $errstr
            );
        }

        return self::failure(
            $channel,
            self::PORT_BLOCKED,
            'We could not open a connection to "' . $host . '" on port ' . $port . '.',
            $errstr
        );
    }
}
