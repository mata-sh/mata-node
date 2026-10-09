<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Core\Database;
use Mata\Core\DatabaseInterface;
use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Watchers\Exceptions\DatabaseWatcherException;
use PDOException;
use Throwable;

class DatabaseWatcher
{
    public const MYSQL_PROCESS_NAMES = 'mysqld|mariadbd';

    private array $connections = [];
    private array $configs = [];
    private CommandRunnerInterface $commandRunner;

    public function __construct(array $databasesConfig, ?CommandRunnerInterface $commandRunner = null)
    {
        $this->configs = $databasesConfig;
        $this->commandRunner = $commandRunner ?? new NativeCommandRunner();
    }

    /**
     * Get a database connection by name.
     *
     * @throws DatabaseWatcherException if the database name doesn't exist
     */
    private function getConnection(string $databaseName): DatabaseInterface
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
                'sql' => $this->commandRunner->countProcesses(self::MYSQL_PROCESS_NAMES),
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

        $result = $this->getConnection($databaseName)->select($sql);

        return (int) $result[0]['Value'];
    }

    public function retrieveActiveThreads(string $databaseName): int
    {
        $sql = <<<'SQL'
            SHOW STATUS LIKE 'Threads_connected'
        SQL;

        $result = $this->getConnection($databaseName)->select($sql);

        return (int) $result[0]['Value'];
    }

    public function retrieveActiveProcesses(string $databaseName): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) as Value
            FROM information_schema.processlist
            WHERE command != 'Sleep'
        SQL;

        $result = $this->getConnection($databaseName)->select($sql);

        return (int) $result[0]['Value'];
    }

    /**
     * Retrieve the process list without query text.
     *
     * `info` carries only the statement keyword (e.g. `SELECT`), because full query text
     * can contain application data, credentials or tokens.
     *
     * @return list<array{id: int, user: ?string, host: ?string, db: ?string, command: ?string, time: int, state: ?string, info: ?string}>
     */
    public function retrieveProcessList(string $databaseName): array
    {
        $sql = <<<'SQL'
            SELECT ID AS id, USER AS user, HOST AS host, DB AS db, COMMAND AS command,
                   TIME AS time, STATE AS state, INFO AS info
            FROM information_schema.processlist
        SQL;

        $rows = $this->getConnection($databaseName)->select($sql);

        return array_map(self::sanitizeProcessRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row Process-list row with lowercase column aliases
     *
     * @return array{id: int, user: ?string, host: ?string, db: ?string, command: ?string, time: int, state: ?string, info: ?string}
     */
    public static function sanitizeProcessRow(array $row): array
    {
        $text = static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user' => $text($row['user'] ?? null),
            'host' => $text($row['host'] ?? null),
            'db' => $text($row['db'] ?? null),
            'command' => $text($row['command'] ?? null),
            'time' => (int) ($row['time'] ?? 0),
            'state' => $text($row['state'] ?? null),
            'info' => self::statementType($text($row['info'] ?? null)),
        ];
    }

    /**
     * Reduce SQL text to its leading statement keyword, skipping comments.
     */
    public static function statementType(?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        $pattern = '~^(?:\s+|/\*.*?\*/|(?:--\s|#)[^\n]*(?:\n|$))*([a-z]+)~is';

        return preg_match($pattern, $sql, $matches) === 1 ? strtoupper($matches[1]) : null;
    }
}
