<?php

declare(strict_types=1);

namespace Mata\Tests\Support;

use RuntimeException;

/**
 * PHP built-in web server wrapper for HTTP level integration tests.
 */
final class HttpTestServer
{
    /** @var null|resource */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private int $port = 0;

    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        private readonly string $documentRoot,
        private readonly string $frontController,
        private readonly array $environment = [],
    ) {}

    public function start(): void
    {
        $this->port = self::allocatePort();

        $command = sprintf(
            '%s -d variables_order=EGPCS -S 127.0.0.1:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($this->documentRoot),
            escapeshellarg($this->frontController)
        );

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            null,
            $this->environment
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start the HTTP test server.');
        }

        $this->process = $process;
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        return (proc_get_status($this->process)['running'] ?? false) === true;
    }

    public function stderr(): string
    {
        $pipe = $this->pipes[2] ?? null;
        if (!is_resource($pipe)) {
            return '';
        }

        stream_set_blocking($pipe, false);

        return (string) stream_get_contents($pipe);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];
    }

    private static function allocatePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (!is_resource($socket)) {
            throw new RuntimeException('Failed to allocate a port for the HTTP test server.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($name)) {
            throw new RuntimeException('Failed to read the allocated test server port.');
        }

        return (int) substr(strrchr($name, ':'), 1);
    }
}
