<?php

declare(strict_types=1);

namespace Mata\Watchers;

use InvalidArgumentException;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Core\Database;

class DatabaseWatcher
{
    private array $connections = [];

    public function __construct(array $databasesConfig)
    {
        foreach ($databasesConfig as $name => $config) {
            $this->connections[$name] = new Database(
                new DatabaseConfig(
                    host: $config['host'],
                    username: $config['username'],
                    password: $config['password'],
                    database: $config['database'],
                    port: $config['port'],
                    description: $config['description'] ?? ''
                )
            );
        }
    }

    /**
     * Get a database connection by name.
     *
     * @throws InvalidArgumentException if the database name doesn't exist
     */
    private function getConnection(string $databaseName): Database
    {
        if (!isset($this->connections[$databaseName])) {
            throw new InvalidArgumentException(
                "Database connection '{$databaseName}' not found. "
                . 'Available: ' . implode(', ', array_keys($this->connections))
            );
        }

        return $this->connections[$databaseName];
    }

    /**
     * Get list of available database names.
     *
     * @return array<string>
     */
    public function getAvailableDatabases(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Retrieve complete database status including all metrics.
     *
     * @return array{
     *     database: string,
     *     max_connections: int,
     *     active_threads: int,
     *     active_processes: int,
     *     process_list: array
     * }
     */
    public function retrieveCompleteStatus(string $databaseName): array
    {
        return [
            'database' => $databaseName,
            'max_connections' => $this->retrieveActiveMaxConnections($databaseName),
            'active_threads' => $this->retrieveActiveThreads($databaseName),
            'active_processes' => $this->retrieveActiveProcesses($databaseName),
            'process_list' => $this->retrieveProcessList($databaseName),
        ];
    }

    /**
     * Retrieve complete status for all available databases.
     *
     * @return array<string, array{
     *     database: string,
     *     max_connections: int,
     *     active_threads: int,
     *     active_processes: int,
     *     process_list: array
     * }>
     */
    public function retrieveAllDatabasesStatus(): array
    {
        $statuses = [];

        foreach (array_keys($this->connections) as $databaseName) {
            $statuses[$databaseName] = $this->retrieveCompleteStatus($databaseName);
        }

        return $statuses;
    }

    public function retrieveActiveMaxConnections(string $databaseName): int
    {
        $sql = <<<'SQL'
            SHOW VARIABLES LIKE 'max_connections'
        SQL;

        $result = $this->getConnection($databaseName)->runQuery($sql);

        return (int) $result[0]['Value'];
    }

    public function retrieveActiveThreads(string $databaseName): int
    {
        $sql = <<<'SQL'
            SHOW STATUS LIKE 'Threads_connected'
        SQL;

        $result = $this->getConnection($databaseName)->runQuery($sql);

        return (int) $result[0]['Value'];
    }

    public function retrieveActiveProcesses(string $databaseName): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) as Value
            FROM information_schema.processlist
            WHERE command != 'Sleep'
        SQL;

        $result = $this->getConnection($databaseName)->runQuery($sql);

        return (int) $result[0]['Value'];
    }

    public function retrieveProcessList(string $databaseName): array
    {
        $sql = <<<'SQL'
            SHOW FULL PROCESSLIST;
        SQL;

        return $this->getConnection($databaseName)->runQuery($sql);
    }
}
