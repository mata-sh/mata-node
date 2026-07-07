<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Core\Database;
use Mata\Watchers\Exceptions\DatabaseWatcherException;
use PDOException;
use Throwable;

class DatabaseWatcher
{
    private array $connections = [];
    private array $configs = [];

    public function __construct(array $databasesConfig)
    {
        $this->configs = $databasesConfig;
    }

    /**
     * Get a database connection by name.
     *
     * @throws DatabaseWatcherException if the database name doesn't exist
     */
    private function getConnection(string $databaseName): Database
    {
        if (!isset($this->configs[$databaseName])) {
            throw DatabaseWatcherException::notFound($databaseName, array_keys($this->configs));
        }

        if (!isset($this->connections[$databaseName])) {
            $config = $this->configs[$databaseName];

            try {
                $this->connections[$databaseName] = new Database(
                    new DatabaseConfig(
                        host: $config['host'],
                        username: $config['username'],
                        password: $config['password'],
                        database: $config['database'],
                        port: $config['port'],
                        description: $config['description'] ?? ''
                    )
                );
            } catch (PDOException $error) {
                $host = $config['host'] ?? 'unknown';
                $port = $config['port'] ?? 'unknown';
                $database = $config['database'] ?? 'unknown';

                throw DatabaseWatcherException::connectionFailed(
                    $databaseName,
                    (string) $host,
                    (string) $port,
                    (string) $database,
                    $error->getMessage()
                );
            }
        }

        return $this->connections[$databaseName];
    }

    /**
     * Get status for configured databases.
     */
    public function getDatabasesStatus(): array
    {
        $databases = [];

        foreach ($this->configs as $databaseName => $config) {
            $databases[$databaseName] = $this->retrieveConfiguredDatabaseStatus((string) $databaseName, $config);
        }

        return [
            'processes' => [
                'sql' => $this->countSqlProcesses(),
            ],
            'databases' => $databases,
        ];
    }

    private function retrieveConfiguredDatabaseStatus(string $databaseName, array $config): array
    {
        $status = [
            'database' => $databaseName,
            'configured_database' => $config['database'] ?? '',
            'host' => $config['host'] ?? '',
            'port' => (int) ($config['port'] ?? 0),
            'description' => $config['description'] ?? '',
            'max_connections' => 0,
            'active_threads' => 0,
            'active_processes' => 0,
            'process_list' => [],
            'connection_error' => '',
        ];

        foreach (['host', 'username', 'password', 'database', 'port'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $config)) {
                $status['connection_error'] = "Missing database config key: {$requiredKey}";

                return $status;
            }
        }

        try {
            return array_merge($status, $this->retrieveCompleteStatus($databaseName));
        } catch (Throwable $error) {
            $status['connection_error'] = $error->getMessage();

            return $status;
        }
    }

    private function countSqlProcesses(): int
    {
        if (!function_exists('shell_exec')) {
            return 0;
        }

        try {
            return (int) shell_exec('pgrep -c sql 2>/dev/null');
        } catch (Throwable) {
            return 0;
        }
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
