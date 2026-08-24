<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Spawns a throwaway read-only IMAP server holding a fixed set of messages.
 *
 * The poller is tested against real protocol traffic — EXAMINE, UID ranges,
 * literals, BODY.PEEK — because the parts most likely to break are exactly the
 * ones a mock would paper over.
 *
 * It also logs every command it receives, which is what lets a test assert
 * that Duely never issues STORE, COPY, MOVE or EXPUNGE against someone's real
 * mailbox.
 */
class FakeImapServer
{
    /** @var resource|null */
    private $process = null;
    private array $pipes = [];

    private function __construct(
        public readonly int $port,
        public readonly string $messagesPath,
        public readonly string $commandLogPath,
    ) {
    }

    /**
     * @param array<int, array{uid:int, headers:string, body?:string}> $messages
     */
    public static function start(array $messages = []): self
    {
        $suffix = bin2hex(random_bytes(6));

        $server = new self(
            self::freePort(),
            sys_get_temp_dir() . '/duely-imap-' . $suffix . '.json',
            sys_get_temp_dir() . '/duely-imap-' . $suffix . '.log',
        );

        $server->setMessages($messages);
        $server->spawn();

        return $server;
    }

    /**
     * Replace the mailbox contents. The server re-reads on every connection.
     *
     * @param array<int, array{uid:int, headers:string, body?:string}> $messages
     */
    public function setMessages(array $messages): void
    {
        file_put_contents($this->messagesPath, json_encode(array_values($messages)));
    }

    /**
     * Every command the server has received this run.
     */
    public function commandLog(): string
    {
        return is_file($this->commandLogPath) ? (string) file_get_contents($this->commandLogPath) : '';
    }

    public function clearCommandLog(): void
    {
        @unlink($this->commandLogPath);
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

        @unlink($this->messagesPath);
        @unlink($this->commandLogPath);
    }

    private function spawn(): void
    {
        $command = [
            PHP_BINARY,
            __DIR__ . '/fake-imap-server.php',
            (string) $this->port,
            $this->messagesPath,
            $this->commandLogPath,
        ];

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the fake IMAP server.');
        }

        $this->process = $process;

        // Wait for READY so a test never connects before the socket is bound.
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

        throw new \RuntimeException('The fake IMAP server did not become ready.');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new \RuntimeException('Could not reserve a loopback port: ' . $errstr);
        }

        $name = (string) stream_socket_get_name($socket, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        return $port;
    }
}
