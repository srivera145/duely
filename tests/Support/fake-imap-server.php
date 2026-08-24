<?php

/**
 * A small read-only IMAP server that serves a fixed set of messages.
 *
 * Used to exercise ImapPoller against real protocol traffic — literals, UID
 * ranges, EXAMINE, BODY.PEEK — rather than against a mocked client.
 *
 * It also records every command it receives to a log file, which is how the
 * tests prove Duely never issues STORE, COPY, MOVE or EXPUNGE against a real
 * mailbox.
 *
 * Run: php fake-imap-server.php <port> <messagesJsonPath> <commandLogPath>
 */
declare(strict_types=1);

$port = (int) ($argv[1] ?? 12200);
$messagesPath = (string) ($argv[2] ?? '');
$commandLog = (string) ($argv[3] ?? '');

$uidValidity = 1000;

/**
 * Re-read the mailbox on every connection, so a test can change what the
 * mailbox contains between polls without restarting the server.
 */
function loadMessages(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

$server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);

if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$deadline = time() + 90;

while (time() < $deadline) {
    $read = [$server];
    $write = $except = null;

    if (@stream_select($read, $write, $except, 1) < 1) {
        continue;
    }

    $conn = @stream_socket_accept($server, 5);

    if (!$conn) {
        continue;
    }

    stream_set_timeout($conn, 10);
    handleSession($conn, loadMessages($messagesPath), $uidValidity, $commandLog);
    fclose($conn);
}

function handleSession($conn, array $messages, int $uidValidity, string $commandLog): void
{
    $CRLF = "\r\n";
    fwrite($conn, '* OK [CAPABILITY IMAP4rev1 UIDPLUS] fake.imap.test ready' . $CRLF);

    $uids = array_map(static fn (array $m): int => (int) $m['uid'], $messages);
    $uidNext = ($uids === [] ? 0 : max($uids)) + 1;

    while (($line = fgets($conn, 16384)) !== false) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if ($commandLog !== '') {
            file_put_contents($commandLog, $line . "\n", FILE_APPEND);
        }

        $parts = explode(' ', $line, 3);
        $tag = $parts[0];
        $command = strtoupper($parts[1] ?? '');
        $args = $parts[2] ?? '';

        switch ($command) {
            case 'LOGIN':
                fwrite($conn, "$tag OK LOGIN completed" . $CRLF);
                break;

            case 'EXAMINE':
            case 'SELECT':
                fwrite($conn,
                    '* ' . count($messages) . ' EXISTS' . $CRLF
                    . '* OK [UIDVALIDITY ' . $uidValidity . '] UIDs valid' . $CRLF
                    . '* OK [UIDNEXT ' . $uidNext . '] Predicted next UID' . $CRLF
                    . "$tag OK [READ-ONLY] EXAMINE completed" . $CRLF
                );
                break;

            case 'STATUS':
                fwrite($conn,
                    '* STATUS "INBOX" (UIDNEXT ' . $uidNext . ' UIDVALIDITY ' . $uidValidity . ')' . $CRLF
                    . "$tag OK STATUS completed" . $CRLF
                );
                break;

            case 'UID':
                handleUid($conn, $tag, $args, $messages, $CRLF);
                break;

            case 'LOGOUT':
                fwrite($conn, '* BYE' . $CRLF . "$tag OK LOGOUT completed" . $CRLF);
                return;

            // Anything that would modify the mailbox is refused outright: this
            // server is opened read-only and says so.
            case 'STORE':
            case 'COPY':
            case 'MOVE':
            case 'EXPUNGE':
            case 'APPEND':
                fwrite($conn, "$tag NO [READ-ONLY] Mailbox is read-only" . $CRLF);
                break;

            default:
                fwrite($conn, "$tag OK completed" . $CRLF);
        }
    }
}

function handleUid($conn, string $tag, string $args, array $messages, string $CRLF): void
{
    $upper = strtoupper($args);

    if (str_starts_with($upper, 'SEARCH')) {
        $from = 1;

        if (preg_match('/UID\s+(\d+):\*/i', $args, $matches) === 1) {
            $from = (int) $matches[1];
        }

        $matching = [];
        foreach ($messages as $message) {
            if ((int) $message['uid'] >= $from) {
                $matching[] = (int) $message['uid'];
            }
        }

        fwrite($conn, '* SEARCH ' . implode(' ', $matching) . $CRLF . "$tag OK UID SEARCH completed" . $CRLF);

        return;
    }

    if (str_starts_with($upper, 'FETCH')) {
        preg_match('/FETCH\s+(\d+)/i', $args, $matches);
        $uid = (int) ($matches[1] ?? 0);

        foreach ($messages as $message) {
            if ((int) $message['uid'] !== $uid) {
                continue;
            }

            $headers = (string) $message['headers'];
            $body = (string) ($message['body'] ?? '');

            // Literals, exactly as a real server sends them.
            fwrite($conn,
                '* 1 FETCH (UID ' . $uid
                . ' BODY[HEADER] {' . strlen($headers) . '}' . $CRLF
                . $headers
                . ' BODY[TEXT] {' . strlen($body) . '}' . $CRLF
                . $body
                . ')' . $CRLF
                . "$tag OK UID FETCH completed" . $CRLF
            );

            return;
        }

        fwrite($conn, "$tag OK UID FETCH completed" . $CRLF);

        return;
    }

    fwrite($conn, "$tag OK completed" . $CRLF);
}
