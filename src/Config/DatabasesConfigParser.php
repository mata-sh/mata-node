<?php

declare(strict_types=1);

namespace Mata\Config;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\DatabaseMonitoringConfig;
use Mata\Watchers\Database\DatabaseDriverFactory;

/**
 * Validates `databases.json` and builds the monitoring configuration.
 *
 * Only checks the file's structure; it never connects to a database, so a database
 * outage cannot fail startup.
 */
final class DatabasesConfigParser
{
    private const TOP_LEVEL_KEYS = ['engine', 'connection', 'databases'];
    private const CONNECTION_KEYS = ['host', 'port', 'username', 'password'];

    /**
     * @param array<mixed> $config Decoded `databases.json`
     *
     * @return null|DatabaseMonitoringConfig Null for `{}` (monitoring off)
     *
     * @throws StartupConfigException When the structure is invalid
     */
    public function parse(array $config): ?DatabaseMonitoringConfig
    {
        if ($config === []) {
            return null;
        }

        $this->assertObject($config, '');
        $this->assertKeys($config, self::TOP_LEVEL_KEYS, '');

        $engine = $this->stringValue($config['engine'], 'engine');
        if (!DatabaseDriverFactory::supports($engine)) {
            $this->fail('engine', 'must be one of: ' . implode(', ', DatabaseDriverFactory::ENGINES));
        }

        $connection = $config['connection'];
        $this->assertObject($connection, 'connection');
        $this->assertKeys($connection, self::CONNECTION_KEYS, 'connection');

        $databases = $config['databases'];
        if (!is_array($databases) || $databases === [] || array_is_list($databases)) {
            $this->fail('databases', 'must be a non-empty object of database name => description');
        }
        $descriptions = [];
        foreach ($databases as $name => $description) {
            $descriptions[$name] = $this->stringValue($description, "databases.{$name}");
        }

        return new DatabaseMonitoringConfig(
            new DatabaseConfig(
                engine: $engine,
                host: $this->stringValue($connection['host'], 'connection.host'),
                port: $this->stringValue($connection['port'], 'connection.port', allowInt: true),
                username: $this->stringValue($connection['username'], 'connection.username'),
                password: $this->stringValue($connection['password'], 'connection.password'),
            ),
            $descriptions,
        );
    }

    /**
     * @phpstan-assert array<string, mixed> $value
     */
    private function assertObject(mixed $value, string $field): void
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->fail($field === '' ? 'file' : $field, 'must be an object');
        }
    }

    /**
     * Every key is required and no other key is allowed.
     *
     * @param array<mixed> $values
     * @param list<string> $keys
     */
    private function assertKeys(array $values, array $keys, string $field): void
    {
        $path = static fn (int|string $key): string => $field === '' ? (string) $key : "{$field}.{$key}";

        foreach (array_keys($values) as $key) {
            if (!in_array($key, $keys, true)) {
                $this->fail($path($key), 'is not a known key');
            }
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $values)) {
                $this->fail($path($key), 'is required');
            }
        }
    }

    private function stringValue(mixed $value, string $field, bool $allowInt = false): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($allowInt && is_int($value)) {
            return (string) $value;
        }

        $this->fail($field, $allowInt ? 'must be a string or integer' : 'must be a string');
    }

    private function fail(string $field, string $problem): never
    {
        throw StartupConfigException::invalid("Invalid databases.json: {$field} {$problem}", ['field' => $field]);
    }
}
