<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

use RuntimeException;

final class RedisSocketClient implements RedisSessionClientInterface
{
    /** @var null|resource */
    private mixed $socket = null;

    private function __construct(
        private string $scheme,
        private string $host,
        private int $port,
        private ?int $database,
        private ?string $username,
        private ?string $password,
        private float $timeoutSeconds
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $dsn = self::resolveDsn($config);
        if ($dsn !== null) {
            return self::fromDsn($dsn, $config);
        }

        return new self(
            'tcp',
            self::stringConfig($config, 'host', '127.0.0.1'),
            self::intConfig($config, 'port', 6379),
            self::nullableIntConfig($config, 'database'),
            self::nullableStringConfig($config, 'username'),
            self::nullableStringConfig($config, 'password'),
            self::floatConfig($config, 'timeout_seconds', 1.0),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function fromDsn(string $dsn, array $config): self
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new RuntimeException('Invalid Redis DSN');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'redis'));
        if (!in_array($scheme, ['redis', 'rediss', 'tcp', 'tls'], true)) {
            throw new RuntimeException('Unsupported Redis DSN scheme');
        }

        $path = (string) ($parts['path'] ?? '');
        $database = null;
        if ($path !== '' && $path !== '/') {
            $database = max(0, (int) ltrim($path, '/'));
        }

        return new self(
            in_array($scheme, ['rediss', 'tls'], true) ? 'tls' : 'tcp',
            (string) ($parts['host'] ?? '127.0.0.1'),
            (int) ($parts['port'] ?? 6379),
            $database,
            isset($parts['user']) ? rawurldecode((string) $parts['user']) : self::nullableStringConfig($config, 'username'),
            isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : self::nullableStringConfig($config, 'password'),
            self::floatConfig($config, 'timeout_seconds', 1.0),
        );
    }

    /**
     * @return array{cursor: string, keys: array<int, string>}
     */
    public function scan(string $cursor, string $match, int $count): array
    {
        $response = $this->command(['SCAN', $cursor, 'MATCH', $match, 'COUNT', (string) $count]);
        if (!is_array($response) || !isset($response[0], $response[1]) || !is_array($response[1])) {
            throw new RuntimeException('Invalid Redis SCAN response');
        }

        $keys = [];
        foreach ($response[1] as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return [
            'cursor' => (string) $response[0],
            'keys' => $keys,
        ];
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * @param array<int, string> $parts
     */
    private function command(array $parts): mixed
    {
        $this->connect();
        $payload = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $payload .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        if (!is_resource($this->socket) || fwrite($this->socket, $payload) === false) {
            throw new RuntimeException('Failed to write Redis command');
        }

        return $this->readResponse();
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $target = sprintf('%s://%s:%d', $this->scheme, $this->host, $this->port);
        $socket = @stream_socket_client($target, $errno, $errstr, $this->timeoutSeconds);
        if ($socket === false) {
            throw new RuntimeException('Redis connection failed');
        }

        stream_set_timeout($socket, (int) $this->timeoutSeconds, (int) (($this->timeoutSeconds - (int) $this->timeoutSeconds) * 1000000));
        $this->socket = $socket;

        if ($this->password !== null && $this->password !== '') {
            if ($this->username !== null && $this->username !== '') {
                $this->command(['AUTH', $this->username, $this->password]);
            } else {
                $this->command(['AUTH', $this->password]);
            }
        }

        if ($this->database !== null) {
            $this->command(['SELECT', (string) $this->database]);
        }
    }

    private function readResponse(): mixed
    {
        $prefix = $this->readBytes(1);

        return match ($prefix) {
            '+' => $this->readLine(),
            '-' => throw new RuntimeException('Redis command failed'),
            ':' => (int) $this->readLine(),
            '$' => $this->readBulkString(),
            '*' => $this->readArray(),
            default => throw new RuntimeException('Invalid Redis response'),
        };
    }

    private function readBulkString(): ?string
    {
        $length = (int) $this->readLine();
        if ($length < 0) {
            return null;
        }

        $value = $this->readBytes($length);
        $this->readBytes(2);

        return $value;
    }

    /**
     * @return null|array<int, mixed>
     */
    private function readArray(): ?array
    {
        $length = (int) $this->readLine();
        if ($length < 0) {
            return null;
        }

        $items = [];
        for ($i = 0; $i < $length; ++$i) {
            $items[] = $this->readResponse();
        }

        return $items;
    }

    private function readLine(): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Redis socket is not connected');
        }

        $line = fgets($this->socket);
        if ($line === false) {
            throw new RuntimeException('Failed to read Redis response');
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Redis socket is not connected');
        }

        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Failed to read Redis response');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveDsn(array $config): ?string
    {
        $dsnEnv = self::nullableStringConfig($config, 'dsn_env');
        if ($dsnEnv !== null) {
            $value = getenv($dsnEnv);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return self::nullableStringConfig($config, 'dsn');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringConfig(array $config, string $key, string $default): string
    {
        return is_scalar($config[$key] ?? null) ? (string) $config[$key] : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nullableStringConfig(array $config, string $key): ?string
    {
        return is_scalar($config[$key] ?? null) ? (string) $config[$key] : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intConfig(array $config, string $key, int $default): int
    {
        return is_numeric($config[$key] ?? null) ? max(1, (int) $config[$key]) : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function nullableIntConfig(array $config, string $key): ?int
    {
        return is_numeric($config[$key] ?? null) ? max(0, (int) $config[$key]) : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function floatConfig(array $config, string $key, float $default): float
    {
        return is_numeric($config[$key] ?? null) ? max(0.1, (float) $config[$key]) : $default;
    }
}
