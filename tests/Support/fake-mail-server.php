<?php
/**
 * Tiny SMTP and IMAP servers that speak just enough protocol to exercise
 * SmtpProbe and ImapClient, including the exact rejection strings Gmail and
 * Outlook return. Run as: php fake_servers.php <smtpPort> <imapPort> <mode>
 *
 * mode: good | badpass | apppassword | notimap
 */
declare(strict_types=1);

$smtpPort = (int) ($argv[1] ?? 12525);
$imapPort = (int) ($argv[2] ?? 12143);
$mode     = (string) ($argv[3] ?? 'good');
$deliveredPath = (string) ($argv[4] ?? (__DIR__ . '/delivered.eml'));

$smtpServer = @stream_socket_server("tcp://127.0.0.1:$smtpPort", $e1, $s1);
$imapServer = @stream_socket_server("tcp://127.0.0.1:$imapPort", $e2, $s2);

if (!$smtpServer || !$imapServer) {
    fwrite(STDERR, "listen failed: $s1 / $s2\n");
    exit(1);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$deadline = time() + 90;

while (time() < $deadline) {
    $read = [$smtpServer, $imapServer];
    $w = $x = null;

    if (@stream_select($read, $w, $x, 1) < 1) {
        continue;
    }

    foreach ($read as $server) {
        $conn = @stream_socket_accept($server, 5);
        if (!$conn) {
            continue;
        }
        stream_set_timeout($conn, 5);

        if ($server === $smtpServer) {
            handleSmtp($conn, $mode, $deliveredPath);
        } else {
            handleImap($conn, $mode);
        }
        fclose($conn);
    }
}

function handleSmtp($conn, string $mode, string $deliveredPath): void
{
    $CRLF = "\r\n";
    fwrite($conn, "220 fake.smtp.test ESMTP Duely-Test" . $CRLF);

    while (($line = fgets($conn, 4096)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 8));

        if (str_starts_with($cmd, 'EHLO') || str_starts_with($cmd, 'HELO')) {
            fwrite($conn, "250-fake.smtp.test" . $CRLF . "250-AUTH LOGIN PLAIN" . $CRLF . "250 OK" . $CRLF);
            continue;
        }

        if (str_starts_with($cmd, 'AUTH')) {
            // Consume the credential exchange for AUTH LOGIN.
            if (stripos($line, 'LOGIN') !== false && stripos($line, 'PLAIN') === false) {
                fwrite($conn, "334 VXNlcm5hbWU6" . $CRLF);
                fgets($conn, 4096);
                fwrite($conn, "334 UGFzc3dvcmQ6" . $CRLF);
                fgets($conn, 4096);
            }

            fwrite($conn, match ($mode) {
                'good' => "235 2.7.0 Accepted" . $CRLF,
                'apppassword' => "534-5.7.9 Application-specific password required. Learn more at" . $CRLF
                    . "534 5.7.9 https://support.google.com/mail/?p=InvalidSecondFactor" . $CRLF,
                default => "535-5.7.8 Username and Password not accepted." . $CRLF
                    . "535 5.7.8 Authentication failed" . $CRLF,
            });
            continue;
        }

        if (str_starts_with($cmd, 'MAIL')) {
            fwrite($conn, "250 2.1.0 Sender OK" . $CRLF);
            continue;
        }

        if (str_starts_with($cmd, 'RCPT')) {
            fwrite($conn, "250 2.1.5 Recipient OK" . $CRLF);
            continue;
        }

        if (str_starts_with($cmd, 'DATA')) {
            fwrite($conn, "354 End data with <CR><LF>.<CR><LF>" . $CRLF);
            $message = '';

            while (($dataLine = fgets($conn, 8192)) !== false) {
                if (rtrim($dataLine, "\r\n") === '.') {
                    break;
                }
                $message .= $dataLine;
            }

            // Record the accepted message so the test can inspect its headers.
            file_put_contents($deliveredPath, $message);
            fwrite($conn, "250 2.0.0 OK: queued as FAKE123" . $CRLF);
            continue;
        }

        if (str_starts_with($cmd, 'QUIT')) {
            fwrite($conn, "221 Bye" . $CRLF);
            return;
        }

        fwrite($conn, "250 OK" . $CRLF);
    }
}

function handleImap($conn, string $mode): void
{
    $CRLF = "\r\n";

    if ($mode === 'notimap') {
        fwrite($conn, "HTTP/1.1 400 Bad Request" . $CRLF);
        return;
    }

    fwrite($conn, "* OK [CAPABILITY IMAP4rev1] fake.imap.test ready" . $CRLF);

    while (($line = fgets($conn, 8192)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = explode(' ', $line, 3);
        $tag = $parts[0];
        $command = strtoupper($parts[1] ?? '');
        $args = $parts[2] ?? '';

        switch ($command) {
            case 'LOGIN':
                fwrite($conn, match ($mode) {
                    'good' => "$tag OK LOGIN completed" . $CRLF,
                    'apppassword' => "$tag NO [ALERT] Application-specific password required: "
                        . "https://support.google.com/accounts/answer/185833" . $CRLF,
                    default => "$tag NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)" . $CRLF,
                });
                break;

            case 'SELECT':
                if (stripos($args, 'INBOX') !== false) {
                    fwrite($conn, "* 3 EXISTS" . $CRLF
                        . "* OK [UIDVALIDITY 1] UIDs valid" . $CRLF
                        . "$tag OK [READ-WRITE] SELECT completed" . $CRLF);
                } else {
                    fwrite($conn, "$tag NO Mailbox does not exist" . $CRLF);
                }
                break;

            case 'UID':
                if (stripos($args, 'SEARCH') === 0) {
                    fwrite($conn, "* SEARCH 101 102 103" . $CRLF . "$tag OK UID SEARCH completed" . $CRLF);
                } else {
                    fwrite($conn, "* 1 FETCH (BODY[HEADER]" . $CRLF
                        . "From: bill@bigco.test" . $CRLF
                        . "Subject: Re: Invoice INV-001" . $CRLF
                        . "Message-ID: <reply-9001@bigco.test>" . $CRLF
                        . ")" . $CRLF
                        . "$tag OK UID FETCH completed" . $CRLF);
                }
                break;

            case 'LOGOUT':
                fwrite($conn, "* BYE" . $CRLF . "$tag OK LOGOUT completed" . $CRLF);
                return;

            default:
                fwrite($conn, "$tag OK completed" . $CRLF);
        }
    }
}
