<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Spawns throwaway SMTP and IMAP servers on loopback so the connection probes
 * can be tested against real protocol traffic rather than mocks.
 *
 * Each instance speaks one scripted outcome — accept the login, reject it, or
 * reject it the way Gmail does — which is what lets the error-classification
 * tests assert on genuine server replies.
 */
class FakeMailServer
{
    public const MODE_GOOD = 'good';
    public const MODE_BAD_PASSWORD = 'badpass';
    public const MODE_APP_PASSWORD = 'apppassword';
    public const MODE_NOT_IMAP = 'notimap';

    /** @var resource|null */
    private $process = null;
    private array $pipes = [];

    private function __construct(
        public readonly int $smtpPort,
        public readonly int $imapPort,
        public readonly string $deliveredPath,
    ) {
    }

    public static function start(string $mode): self
    {
        [$smtpPort, $imapPort] = self::freePortPair();

        $delivered = sys_get_temp_dir() . '/duely-delivered-' . bin2hex(random_bytes(6)) . '.eml';
        $server = new self($smtpPort, $imapPort, $delivered);
        $server->spawn($mode);

        return $server;
    }

    /**
     * The raw message the SMTP server accepted, if any.
     */
    public function deliveredMessage(): ?string
    {
        return is_file($this->deliveredPath) ? (string) file_get_contents($this->deliveredPath) : null;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;

        @unlink($this->deliveredPath);
    }

    private function spawn(string $mode): void
    {
        $script = __DIR__ . '/fake-mail-server.php';

        $command = [
            PHP_BINARY,
            $script,
            (string) $this->smtpPort,
            (string) $this->imapPort,
            $mode,
            $this->deliveredPath,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $this->pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the fake mail server.');
        }

        $this->process = $process;

        // The child prints READY once both listeners are bound. Waiting for it
        // avoids a race where the test connects before the socket exists.
        stream_set_blocking($this->pipes[1], false);
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $line = fgets($this->pipes[1]);

            if (is_string($line) && str_contains($line, 'READY')) {
                return;
            }

            usleep(20_000);
        }

        $this->stop();

        throw new \RuntimeException('The fake mail server did not become ready.');
    }

    /**
     * Bind two ports, note the numbers, and release them. A short race window
     * remains, but it is far more reliable than guessing fixed ports that a
     * previous run may still hold.
     *
     * @return array{0:int, 1:int}
     */
    private static function freePortPair(): array
    {
        $ports = [];

        foreach ([0, 1] as $ignored) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

            if ($socket === false) {
                throw new \RuntimeException('Could not reserve a loopback port: ' . $errstr);
            }

            $name = stream_socket_get_name($socket, false);
            $ports[] = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
            fclose($socket);
        }

        return [$ports[0], $ports[1]];
    }
}
