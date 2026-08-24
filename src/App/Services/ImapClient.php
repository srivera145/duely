<?php

namespace Keel\App\Services;

use Throwable;

/**
 * A minimal IMAP4rev1 client built on stream sockets.
 *
 * PHP's ext-imap is not available on this install and is deprecated as of PHP
 * 8.4, so Duely speaks IMAP directly. The surface is deliberately small: log in,
 * select a mailbox, search, and fetch headers — everything the reply poller
 * needs and nothing more.
 *
 * Errors are translated into ConnectionDiagnosis so a failed login reads as
 * "wrong password" rather than as an untagged protocol string.
 */
class ImapClient
{
    private const CONNECT_TIMEOUT = 12;
    private const READ_TIMEOUT = 20;

    /** @var resource|null */
    private $stream = null;
    private int $tag = 0;
    private array $capabilities = [];

    /**
     * Verify credentials by opening a real IMAP session and selecting the folder.
     */
    public function test(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $folder = 'INBOX',
        string $provider = ProviderPresets::PROVIDER_SMTP
    ): ConnectionDiagnosis {
        if ($host === '' || $username === '' || $password === '') {
            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::NOT_CONFIGURED,
                'Enter an incoming server, username, and password before testing.'
            );
        }

        $reachability = SocketProbe::reach('imap', $host, $port, self::CONNECT_TIMEOUT);

        if (!$reachability->succeeded()) {
            return $reachability;
        }

        try {
            $connected = $this->connect($host, $port, $encryption);

            if (!$connected->succeeded()) {
                return $connected;
            }

            $authenticated = $this->login($username, $password, $provider);

            if (!$authenticated->succeeded()) {
                return $authenticated;
            }

            $selected = $this->select($folder);

            if (!$selected->succeeded()) {
                return $selected;
            }

            return ConnectionDiagnosis::ok(
                'imap',
                'Signed in to ' . $host . ' and opened "' . $folder . '" successfully.'
            );
        } catch (Throwable $exception) {
            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::UNKNOWN,
                'Reading the mailbox on "' . $host . '" failed unexpectedly.',
                self::scrub($exception->getMessage())
            );
        } finally {
            $this->disconnect();
        }
    }

    // ------------------------------------------------------------- session

    public function connect(string $host, int $port, string $encryption): ConnectionDiagnosis
    {
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';

        // Failures arrive through errno/errstr, so suppression keeps a probe
        // failure out of the PHP error log without hiding information.
        $stream = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($stream === false) {
            $lower = strtolower($errstr);

            if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'handshake')) {
                return ConnectionDiagnosis::failure(
                    'imap',
                    ConnectionDiagnosis::TLS_FAILED,
                    'The encrypted connection to "' . $host . '" could not be established on port ' . $port . '.',
                    $errstr,
                    'Port 993 expects SSL/TLS and port 143 expects STARTTLS. Try switching the encryption setting.'
                );
            }

            return ConnectionDiagnosis::fromSocketError('imap', $host, $port, $errno, $errstr ?: 'Connection failed.');
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, self::READ_TIMEOUT);

        $greeting = $this->readLine();

        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::PORT_BLOCKED,
                'Port ' . $port . ' on "' . $host . '" answered, but it is not an IMAP server.',
                self::scrub($greeting),
                'Reading mail usually uses port 993 (SSL/TLS) or 143 (STARTTLS).'
            );
        }

        if ($encryption === 'tls') {
            $upgraded = $this->startTls($host, $port);

            if (!$upgraded->succeeded()) {
                return $upgraded;
            }
        }

        return ConnectionDiagnosis::ok('imap', 'Connected to ' . $host . '.');
    }

    private function startTls(string $host, int $port): ConnectionDiagnosis
    {
        $response = $this->command('STARTTLS');

        if (!$response['ok']) {
            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::TLS_FAILED,
                'The server on port ' . $port . ' would not start an encrypted session.',
                self::scrub($response['text']),
                'Port 993 usually expects SSL/TLS rather than STARTTLS.'
            );
        }

        $enabled = @stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($enabled !== true) {
            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::TLS_FAILED,
                'The TLS handshake with "' . $host . '" failed.',
                null,
                'The server certificate may not match its hostname.'
            );
        }

        return ConnectionDiagnosis::ok('imap', 'Upgraded to TLS.');
    }

    public function login(string $username, string $password, string $provider = ProviderPresets::PROVIDER_SMTP): ConnectionDiagnosis
    {
        $response = $this->command('LOGIN ' . self::quote($username) . ' ' . self::quote($password), true);

        if ($response['ok']) {
            return ConnectionDiagnosis::ok('imap', 'Authenticated.');
        }

        $text = $response['text'];
        $lower = strtolower($text);

        // Gmail says "Application-specific password required" verbatim, and
        // Microsoft says "basic authentication is disabled". Both are precise.
        if (str_contains($lower, 'application-specific password')
            || str_contains($lower, 'app password')
            || str_contains($lower, 'basic authentication is disabled')
            || str_contains($lower, 'authenticate failed')) {
            if (AppPasswordGuidance::providerRequiresAppPassword($provider)) {
                return AppPasswordGuidance::diagnosis('imap', $provider, self::scrub($text));
            }
        }

        if (str_contains($lower, 'authenticationfailed')
            || str_contains($lower, 'invalid credentials')
            || str_contains($lower, 'login failed')
            || str_contains($lower, 'authentication failed')
            || str_contains($lower, 'invalid login')) {
            if (AppPasswordGuidance::providerRequiresAppPassword($provider)) {
                return AppPasswordGuidance::diagnosis('imap', $provider, self::scrub($text));
            }

            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::AUTH_FAILED,
                'The mail server rejected that username and password.',
                self::scrub($text),
                'Check for a typo, and confirm the username is your full email address rather than just the part before the @.'
            );
        }

        if (str_contains($lower, 'imap access is disabled') || str_contains($lower, 'imap is disabled')) {
            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::AUTH_FAILED,
                'IMAP access is switched off for this mailbox.',
                self::scrub($text),
                'Turn IMAP on in your mail provider settings, then test again. Duely needs it to notice when a client replies.'
            );
        }

        if (str_contains($lower, 'too many') || str_contains($lower, 'try again later')) {
            return ConnectionDiagnosis::failure(
                'imap',
                ConnectionDiagnosis::RATE_LIMITED,
                'The mail server is temporarily refusing logins. Wait a minute and try again.',
                self::scrub($text)
            );
        }

        return ConnectionDiagnosis::failure(
            'imap',
            ConnectionDiagnosis::AUTH_FAILED,
            'The mail server would not accept these sign-in details.',
            self::scrub($text)
        );
    }

    public function select(string $folder): ConnectionDiagnosis
    {
        $response = $this->command('SELECT ' . self::quote($folder));

        if ($response['ok']) {
            return ConnectionDiagnosis::ok('imap', 'Opened ' . $folder . '.');
        }

        return ConnectionDiagnosis::failure(
            'imap',
            ConnectionDiagnosis::MAILBOX_MISSING,
            'We signed in, but the folder "' . $folder . '" does not exist on this account.',
            self::scrub($response['text']),
            'Most accounts use INBOX. Check the exact spelling in your mail app.'
        );
    }

    /**
     * Open a mailbox READ-ONLY.
     *
     * EXAMINE is SELECT without write access: the server itself refuses any
     * attempt to change flags, move, or expunge. Duely polls someone's personal
     * inbox, so the guarantee that nothing can be modified is made by the
     * protocol rather than by our own discipline.
     *
     * @return array{ok:bool, uidnext:?int, uidvalidity:?int, exists:int, diagnosis:ConnectionDiagnosis}
     */
    public function examine(string $folder): array
    {
        $response = $this->command('EXAMINE ' . self::quote($folder));

        if (!$response['ok']) {
            return [
                'ok' => false,
                'uidnext' => null,
                'uidvalidity' => null,
                'exists' => 0,
                'diagnosis' => ConnectionDiagnosis::failure(
                    'imap',
                    ConnectionDiagnosis::MAILBOX_MISSING,
                    'The folder "' . $folder . '" could not be opened.',
                    self::scrub($response['text'])
                ),
            ];
        }

        $uidNext = null;
        $uidValidity = null;
        $exists = 0;

        foreach ($response['lines'] as $line) {
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $line, $matches) === 1) {
                $uidNext = (int) $matches[1];
            }

            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $matches) === 1) {
                $uidValidity = (int) $matches[1];
            }

            if (preg_match('/^\* (\d+) EXISTS/i', $line, $matches) === 1) {
                $exists = (int) $matches[1];
            }
        }

        // Some servers only report UIDNEXT in a STATUS response.
        if ($uidNext === null) {
            $status = $this->command('STATUS ' . self::quote($folder) . ' (UIDNEXT UIDVALIDITY)');

            foreach ($status['lines'] as $line) {
                if (preg_match('/UIDNEXT (\d+)/i', $line, $matches) === 1) {
                    $uidNext = (int) $matches[1];
                }

                if ($uidValidity === null && preg_match('/UIDVALIDITY (\d+)/i', $line, $matches) === 1) {
                    $uidValidity = (int) $matches[1];
                }
            }
        }

        return [
            'ok' => true,
            'uidnext' => $uidNext,
            'uidvalidity' => $uidValidity,
            'exists' => $exists,
            'diagnosis' => ConnectionDiagnosis::ok('imap', 'Opened ' . $folder . ' read-only.'),
        ];
    }

    /**
     * UIDs at or above a cursor.
     *
     * `UID SEARCH UID n:*` is the standard way to ask for everything new. The
     * range is inclusive and servers may return the last existing UID even when
     * n is beyond it, so the caller still filters.
     *
     * @return int[]
     */
    public function uidsFrom(int $fromUid): array
    {
        return $this->searchUids('UID ' . max(1, $fromUid) . ':*');
    }

    /**
     * Headers plus a short body extract for one message, without marking it read.
     *
     * BODY.PEEK is the read-only form of BODY: it returns content without
     * setting the \Seen flag. Using BODY here instead would silently mark a
     * client's unread mail as read, which is unacceptable in someone's own
     * inbox. Only the first slice of the body is requested, because Duely
     * stores a snippet and never the whole message.
     *
     * @return array{uid:int, headers:string, body:string}|null
     */
    public function fetchMessage(int $uid, int $bodyBytes = 2048): ?array
    {
        $response = $this->command(
            'UID FETCH ' . $uid . ' (BODY.PEEK[HEADER] BODY.PEEK[TEXT]<0.' . max(0, $bodyBytes) . '>)'
        );

        if (!$response['ok']) {
            return null;
        }

        $headers = '';
        $body = '';

        // The literals collected by command() arrive in request order.
        foreach ($response['literals'] as $index => $literal) {
            $index === 0 ? $headers = $literal : $body .= $literal;
        }

        if ($headers === '') {
            return null;
        }

        return ['uid' => $uid, 'headers' => trim($headers), 'body' => $body];
    }

    /**
     * UIDs in the selected mailbox matching an IMAP search key.
     *
     * @return int[]
     */
    public function searchUids(string $criteria): array
    {
        $response = $this->command('UID SEARCH ' . $criteria);

        if (!$response['ok']) {
            return [];
        }

        $uids = [];

        foreach ($response['lines'] as $line) {
            if (!str_starts_with($line, '* SEARCH')) {
                continue;
            }

            foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $token) {
                if ($token !== '' && ctype_digit($token)) {
                    $uids[] = (int) $token;
                }
            }
        }

        sort($uids);

        return $uids;
    }

    /**
     * Raw headers for one UID, for threading and reply classification.
     */
    public function fetchHeaders(int $uid): ?string
    {
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[HEADER])');

        if (!$response['ok']) {
            return null;
        }

        // The header block arrives as a literal, not as protocol lines.
        return isset($response['literals'][0]) ? trim($response['literals'][0]) : null;
    }

    public function disconnect(): void
    {
        if ($this->stream === null) {
            return;
        }

        // A server that has already dropped the connection makes LOGOUT throw;
        // the socket is being closed either way.
        try {
            $this->command('LOGOUT');
        } catch (Throwable) {
            // Nothing to recover — fall through to closing the stream.
        }

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    // ------------------------------------------------------------ protocol

    /**
     * Issue a tagged command and collect every line up to its completion.
     *
     * IMAP sends bulk data as literals: a line ending in `{N}` means the next N
     * octets are raw content, which may itself contain CRLFs and text that
     * looks like protocol. Reading those line-by-line would corrupt any message
     * containing a blank line — that is, all of them — so a literal is read as
     * exactly N bytes and collected separately.
     *
     * @return array{ok:bool, text:string, lines:string[], literals:string[]}
     */
    private function command(string $command, bool $sensitive = false): array
    {
        if ($this->stream === null) {
            return ['ok' => false, 'text' => 'Not connected.', 'lines' => [], 'literals' => []];
        }

        $tag = 'D' . str_pad((string) (++$this->tag), 4, '0', STR_PAD_LEFT);
        fwrite($this->stream, $tag . ' ' . $command . "\r\n");

        $lines = [];
        $literals = [];

        while (true) {
            $line = $this->readLine();

            if ($line === '') {
                return [
                    'ok' => false,
                    'text' => 'The server closed the connection unexpectedly.',
                    'lines' => $lines,
                    'literals' => $literals,
                ];
            }

            if (str_starts_with($line, $tag . ' ')) {
                $completion = substr($line, strlen($tag) + 1);
                $ok = str_starts_with(strtoupper($completion), 'OK');

                return [
                    'ok' => $ok,
                    // A sensitive command echoes nothing back that could contain
                    // the credential, but the guard keeps that guarantee local.
                    'text' => $sensitive ? self::scrub($completion) : $completion,
                    'lines' => $lines,
                    'literals' => $literals,
                ];
            }

            $lines[] = $line;

            // A trailing {N} announces N octets of raw data on the next read.
            if (preg_match('/\{(\d+)\}$/', $line, $matches) === 1) {
                $literals[] = $this->readBytes((int) $matches[1]);
            }
        }
    }

    /**
     * Read exactly N octets, however many reads that takes.
     */
    private function readBytes(int $length): string
    {
        if ($this->stream === null || $length <= 0) {
            return '';
        }

        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($this->stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function readLine(): string
    {
        if ($this->stream === null) {
            return '';
        }

        $line = fgets($this->stream, 8192);

        if ($line === false) {
            return '';
        }

        return rtrim($line, "\r\n");
    }

    /**
     * IMAP quoted-string literal per RFC 3501 section 4.3.
     */
    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Remove anything resembling a credential before a protocol string is
     * allowed anywhere near a response body.
     */
    private static function scrub(string $text): string
    {
        $text = preg_replace('/\bLOGIN\s+"?[^"\s]+"?\s+"?[^"\s]+"?/i', 'LOGIN [redacted]', $text) ?? $text;

        return trim(preg_replace('/\b[A-Za-z0-9+\/]{40,}={0,2}\b/', '[redacted]', $text) ?? $text);
    }
}
