<?php

declare(strict_types=1);

namespace Mata\Config;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\DatabaseMonitoringConfig;
use Mata\Watchers\Database\DatabaseDriverFactory;

/**
 * Validates `databases.json` and builds the monitoring configuration.
 *
 * Only checks the file's structure and references; it never connects to a database,
 * so a database outage cannot fail startup.
 */
final class DatabasesConfigParser
{
    private const TOP_LEVEL_KEYS = ['connections', 'monitoring'];
    private const CONNECTION_KEYS = ['engine', 'host', 'port', 'username', 'password', 'database', 'description'];
    private const REQUIRED_CONNECTION_KEYS = ['host', 'port', 'username', 'password', 'database'];
    private const MONITORING_KEYS = ['instance', 'connection', 'databases'];

    /**
     * @param array<mixed> $config Decoded `databases.json`
     *
     * @return null|DatabaseMonitoringConfig Null when no `monitoring` block is configured
     *
     * @throws StartupConfigException When the structure or a reference is invalid
     */
    public function parse(array $config): ?DatabaseMonitoringConfig
    {
        $this->assertKnownKeys($config, self::TOP_LEVEL_KEYS, '');

        $connections = $this->parseConnections($config['connections'] ?? []);

        if (!array_key_exists('monitoring', $config)) {
            return null;
        }

        return $this->parseMonitoring($config['monitoring'], $connections);
    }

    /**
     * @return array<string, DatabaseConfig>
     */
    private function parseConnections(mixed $connections): array
    {
        if (!is_array($connections) || ($connections !== [] && array_is_list($connections))) {
            $this->fail('connections', 'must be an object of named connections');
        }

        $parsed = [];
        foreach ($connections as $name => $connection) {
            $name = (string) $name;
            $field = "connections.{$name}";

            if (!is_array($connection) || ($connection !== [] && array_is_list($connection))) {
                $this->fail($field, 'must be an object');
            }

            $this->assertKnownKeys($connection, self::CONNECTION_KEYS, $field);

            foreach (self::REQUIRED_CONNECTION_KEYS as $key) {
                if (!array_key_exists($key, $connection)) {
                    $this->fail("{$field}.{$key}", 'is required');
                }
            }

            $engine = $this->stringValue($connection['engine'] ?? DatabaseDriverFactory::DEFAULT_ENGINE, "{$field}.engine");
            if (!DatabaseDriverFactory::supports($engine)) {
                $this->fail("{$field}.engine", 'must be one of: ' . implode(', ', DatabaseDriverFactory::ENGINES));
            }

            $parsed[$name] = new DatabaseConfig(
                name: $name,
                engine: $engine,
                host: $this->stringValue($connection['host'], "{$field}.host"),
                port: $this->stringValue($connection['port'], "{$field}.port", allowInt: true),
                username: $this->stringValue($connection['username'], "{$field}.username"),
                password: $this->stringValue($connection['password'], "{$field}.password"),
                database: $this->stringValue($connection['database'], "{$field}.database"),
                description: $this->stringValue($connection['description'] ?? '', "{$field}.description"),
            );
        }

        return $parsed;
    }

    /**
     * @param array<string, DatabaseConfig> $connections
     */
    private function parseMonitoring(mixed $monitoring, array $connections): DatabaseMonitoringConfig
    {
        if (!is_array($monitoring) || ($monitoring !== [] && array_is_list($monitoring))) {
            $this->fail('monitoring', 'must be an object');
        }

        $this->assertKnownKeys($monitoring, self::MONITORING_KEYS, 'monitoring');

        $connectionName = $this->stringValue($monitoring['connection'] ?? null, 'monitoring.connection');
        $connection = $connections[$connectionName] ?? $this->fail('monitoring.connection', "references unknown connection '{$connectionName}'");

        $instance = $this->stringValue($monitoring['instance'] ?? $connectionName, 'monitoring.instance');
        if (trim($instance) === '') {
            $this->fail('monitoring.instance', 'must not be empty');
        }

        $databaseNames = $monitoring['databases'] ?? [$connectionName];
        if (!is_array($databaseNames) || !array_is_list($databaseNames) || $databaseNames === []) {
            $this->fail('monitoring.databases', 'must be a non-empty list of connection names');
        }

        $databases = [];
        foreach ($databaseNames as $index => $name) {
            $name = $this->stringValue($name, "monitoring.databases.{$index}");
            $databases[] = $connections[$name] ?? $this->fail("monitoring.databases.{$index}", "references unknown connection '{$name}'");
        }

        return new DatabaseMonitoringConfig($instance, $connection, $databases);
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $known
     */
    private function assertKnownKeys(array $values, array $known, string $field): void
    {
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $known, true)) {
                $path = $field === '' ? (string) $key : "{$field}.{$key}";
                $this->fail($path, 'is not a known key');
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
