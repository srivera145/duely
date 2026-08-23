<?php

namespace Keel\App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Throwable;

/**
 * Performs a real SMTP conversation against the user's server: TCP connect,
 * EHLO, STARTTLS where required, and a genuine AUTH exchange.
 *
 * Nothing is ever saved on the strength of a guess — if this class does not
 * report OK, no credential is written.
 *
 * PHPMailer's SMTP debug stream carries the base64 AUTH payload, so debug
 * output is captured into memory, scrubbed of the credential lines, and only
 * ever surfaced as `detail` on a failure. It is never written to a log.
 */
class SmtpProbe
{
    private const CONNECT_TIMEOUT = 12;

    /**
     * Verify credentials by opening a real authenticated session.
     */
    public function test(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $provider = ProviderPresets::PROVIDER_SMTP
    ): ConnectionDiagnosis {
        if ($host === '' || $username === '' || $password === '') {
            return ConnectionDiagnosis::failure(
                'smtp',
                ConnectionDiagnosis::NOT_CONFIGURED,
                'Enter a sending server, username, and password before testing.'
            );
        }

        // Pre-flight the raw socket so DNS and firewall problems are reported as
        // themselves rather than as a generic "SMTP connect() failed".
        $reachability = SocketProbe::reach('smtp', $host, $port, self::CONNECT_TIMEOUT);

        if (!$reachability->succeeded()) {
            return $reachability;
        }

        $transcript = [];
        $smtp = new SMTP();
        $smtp->do_debug = SMTP::DEBUG_SERVER;
        $smtp->Debugoutput = static function (string $line) use (&$transcript): void {
            $transcript[] = self::scrub($line);
        };
        $smtp->Timeout = self::CONNECT_TIMEOUT;

        try {
            $options = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ];

            $connectHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;

            if (!$smtp->connect($connectHost, $port, self::CONNECT_TIMEOUT, $options)) {
                return $this->classify($smtp, $transcript, $host, $port, $encryption, $provider);
            }

            if (!$smtp->hello(self::heloName())) {
                return $this->classify($smtp, $transcript, $host, $port, $encryption, $provider);
            }

            if ($encryption === 'tls') {
                if (!$smtp->startTLS()) {
                    return ConnectionDiagnosis::failure(
                        'smtp',
                        ConnectionDiagnosis::TLS_FAILED,
                        'The server would not start an encrypted session on port ' . $port . '.',
                        self::transcriptTail($transcript),
                        'Port 587 normally uses STARTTLS and port 465 uses SSL/TLS. Try switching the encryption setting.'
                    );
                }

                // RFC 3207: re-issue EHLO over the now-encrypted channel.
                if (!$smtp->hello(self::heloName())) {
                    return $this->classify($smtp, $transcript, $host, $port, $encryption, $provider);
                }
            }

            if (!$smtp->authenticate($username, $password)) {
                return $this->classifyAuth($smtp, $transcript, $provider);
            }

            $smtp->quit();
            $smtp->close();

            return ConnectionDiagnosis::ok('smtp', 'Signed in to ' . $host . ' and authenticated successfully.');
        } catch (Throwable $exception) {
            $smtp->close();

            return $this->classifyThrowable($exception, $transcript, $host, $port, $encryption, $provider);
        }
    }

    /**
     * Send a real message through the verified account.
     */
    public function send(
        array $connection,
        string $fromEmail,
        string $fromName,
        ?string $replyTo,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $headers = []
    ): ConnectionDiagnosis {
        $mail = new PHPMailer(true);
        $transcript = [];

        try {
            $mail->isSMTP();
            $mail->Host = $connection['host'];
            $mail->Port = (int) $connection['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $connection['username'];
            $mail->Password = $connection['password'];
            $mail->Timeout = self::CONNECT_TIMEOUT;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->SMTPSecure = match ($connection['encryption']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mail->SMTPAutoTLS = $connection['encryption'] !== 'none';

            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $line) use (&$transcript): void {
                $transcript[] = self::scrub($line);
            };

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);

            if ($replyTo !== null && $replyTo !== '') {
                $mail->addReplyTo($replyTo, $fromName);
            }

            // RFC822 threading headers for chase follow-ups.
            foreach ($headers as $name => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                if (strcasecmp($name, 'Message-ID') === 0) {
                    $mail->MessageID = (string) $value;
                    continue;
                }

                $mail->addCustomHeader((string) $name, (string) $value);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();

            return ConnectionDiagnosis::ok('smtp', 'Message accepted by ' . $connection['host'] . ' for delivery.');
        } catch (Throwable $exception) {
            return $this->classifyThrowable(
                $exception,
                $transcript,
                (string) $connection['host'],
                (int) $connection['port'],
                (string) $connection['encryption'],
                (string) ($connection['provider'] ?? ProviderPresets::PROVIDER_SMTP)
            );
        }
    }

    // -------------------------------------------------------- classification

    private function classifyAuth(SMTP $smtp, array $transcript, string $provider): ConnectionDiagnosis
    {
        $error = $smtp->getError();
        $reply = strtolower((string) ($error['detail'] ?? '') . ' ' . (string) $smtp->getLastReply());
        $code = (int) ($error['smtp_code'] ?? 0);

        $smtp->close();

        // Gmail and Microsoft say this in as many words; trust them over heuristics.
        if (self::mentionsAppPassword($reply)) {
            return AppPasswordGuidance::diagnosis('smtp', $provider, self::transcriptTail($transcript));
        }

        if ($code === 535 || $code === 534 || str_contains($reply, 'authentication failed')
            || str_contains($reply, 'invalid credentials') || str_contains($reply, 'bad username')
            || str_contains($reply, 'authentication credentials invalid')) {
            if (AppPasswordGuidance::providerRequiresAppPassword($provider)) {
                return AppPasswordGuidance::diagnosis('smtp', $provider, self::transcriptTail($transcript));
            }

            return ConnectionDiagnosis::failure(
                'smtp',
                ConnectionDiagnosis::AUTH_FAILED,
                'The server rejected that username and password.',
                self::transcriptTail($transcript),
                'Check for a typo, and confirm the username is your full email address rather than just the part before the @.'
            );
        }

        if ($code === 421 || $code === 454 || str_contains($reply, 'too many')) {
            return ConnectionDiagnosis::failure(
                'smtp',
                ConnectionDiagnosis::RATE_LIMITED,
                'The mail server is temporarily refusing new connections. Wait a minute and try again.',
                self::transcriptTail($transcript)
            );
        }

        return ConnectionDiagnosis::failure(
            'smtp',
            ConnectionDiagnosis::AUTH_FAILED,
            'The server would not accept these sign-in details.',
            self::transcriptTail($transcript)
        );
    }

    private function classify(
        SMTP $smtp,
        array $transcript,
        string $host,
        int $port,
        string $encryption,
        string $provider
    ): ConnectionDiagnosis {
        $error = $smtp->getError();
        $detail = trim((string) ($error['error'] ?? '') . ' ' . (string) ($error['detail'] ?? ''));
        $haystack = strtolower($detail . ' ' . implode(' ', $transcript));

        $smtp->close();

        if (str_contains($haystack, 'ssl') || str_contains($haystack, 'tls') || str_contains($haystack, 'certificate')) {
            return ConnectionDiagnosis::failure(
                'smtp',
                ConnectionDiagnosis::TLS_FAILED,
                'The encrypted connection to "' . $host . '" could not be established on port ' . $port . '.',
                self::transcriptTail($transcript),
                $encryption === 'ssl'
                    ? 'This port may expect STARTTLS instead of SSL/TLS. Port 587 is usually STARTTLS.'
                    : 'This port may expect SSL/TLS instead of STARTTLS. Port 465 is usually SSL/TLS.'
            );
        }

        return ConnectionDiagnosis::failure(
            'smtp',
            ConnectionDiagnosis::PORT_BLOCKED,
            'We reached "' . $host . '" but it did not complete an SMTP handshake on port ' . $port . '.',
            self::transcriptTail($transcript),
            'Double-check the port. Sending usually uses 587 (STARTTLS) or 465 (SSL/TLS).'
        );
    }

    private function classifyThrowable(
        Throwable $exception,
        array $transcript,
        string $host,
        int $port,
        string $encryption,
        string $provider
    ): ConnectionDiagnosis {
        $message = strtolower($exception->getMessage());

        if (self::mentionsAppPassword($message)) {
            return AppPasswordGuidance::diagnosis('smtp', $provider, self::transcriptTail($transcript));
        }

        if (str_contains($message, 'could not authenticate') || str_contains($message, 'authenticate')) {
            if (AppPasswordGuidance::providerRequiresAppPassword($provider)) {
                return AppPasswordGuidance::diagnosis('smtp', $provider, self::transcriptTail($transcript));
            }

            return ConnectionDiagnosis::failure(
                'smtp',
                ConnectionDiagnosis::AUTH_FAILED,
                'The server rejected that username and password.',
                self::transcriptTail($transcript),
                'Confirm the username is your full email address.'
            );
        }

        if (str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls')) {
            return ConnectionDiagnosis::failure(
                'smtp',
                ConnectionDiagnosis::TLS_FAILED,
                'The encrypted connection to "' . $host . '" failed.',
                self::transcriptTail($transcript),
                'Try switching between STARTTLS (port 587) and SSL/TLS (port 465).'
            );
        }

        if (str_contains($message, 'connect')) {
            return ConnectionDiagnosis::failure(
                'smtp',
                ConnectionDiagnosis::PORT_BLOCKED,
                'We could not open an SMTP session with "' . $host . '" on port ' . $port . '.',
                self::transcriptTail($transcript)
            );
        }

        return ConnectionDiagnosis::failure(
            'smtp',
            ConnectionDiagnosis::UNKNOWN,
            'Sending mail through "' . $host . '" failed for an unexpected reason.',
            self::transcriptTail($transcript)
        );
    }

    private static function mentionsAppPassword(string $haystack): bool
    {
        $haystack = strtolower($haystack);

        return str_contains($haystack, 'application-specific password')
            || str_contains($haystack, 'app password')
            || str_contains($haystack, 'app-specific')
            || str_contains($haystack, '5.7.9')
            || str_contains($haystack, 'basic authentication is disabled')
            || str_contains($haystack, 'authentication unsuccessful')
            || str_contains($haystack, 'smtp auth is disabled');
    }

    private static function heloName(): string
    {
        $host = parse_url((string) \Keel\Core\Env::get('APP_URL', ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * Strip anything that could carry a credential out of a debug line.
     */
    private static function scrub(string $line): string
    {
        $line = trim($line);

        // AUTH exchanges carry base64 of the username and password.
        if (preg_match('/^(CLIENT -> SERVER: )?AUTH\b/i', $line) === 1) {
            return 'AUTH [redacted]';
        }

        if (preg_match('/^(CLIENT -> SERVER: )?[A-Za-z0-9+\/]{16,}={0,2}$/', $line) === 1) {
            return '[redacted]';
        }

        return preg_replace('/\b[A-Za-z0-9+\/]{40,}={0,2}\b/', '[redacted]', $line) ?? '';
    }

    /**
     * The last few scrubbed protocol lines, for a support disclosure.
     */
    private static function transcriptTail(array $transcript): ?string
    {
        $lines = array_values(array_filter(array_map('trim', $transcript)));

        if ($lines === []) {
            return null;
        }

        return implode("\n", array_slice($lines, -6));
    }
}
