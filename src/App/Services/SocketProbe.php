<?php

namespace Keel\App\Services;

/**
 * A plain TCP reachability check run before any protocol conversation.
 *
 * Both SMTP and IMAP libraries collapse "that hostname does not exist" and
 * "port 587 is firewalled" into the same opaque connect failure. Probing the
 * socket first lets Duely tell the user which of those actually happened.
 */
class SocketProbe
{
    public static function reach(string $channel, string $host, int $port, int $timeout = 12): ConnectionDiagnosis
    {
        $errno = 0;
        $errstr = '';

        // Errors are captured through the errno/errstr out-params rather than
        // raised, so suppression here loses nothing and keeps a failed probe
        // from emitting a warning into the PHP error log.
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            return ConnectionDiagnosis::fromSocketError($channel, $host, $port, $errno, $errstr ?: 'Connection failed.');
        }

        fclose($socket);

        return ConnectionDiagnosis::ok($channel, 'Reached ' . $host . ' on port ' . $port . '.');
    }
}
