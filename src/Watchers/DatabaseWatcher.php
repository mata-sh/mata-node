<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\DatabaseMonitoringConfig;
use Mata\Core\Database;
use Mata\Core\DatabaseInterface;
use Mata\Watchers\Database\DatabaseDriverFactory;
use Mata\Watchers\Database\DatabaseDriverInterface;
use Mata\Watchers\Database\ProcessList;
use Mata\Watchers\Exceptions\DatabaseWatcherException;
use PDOException;
use UnexpectedValueException;

/**
 * Serves `/databases/overview` and `/databases/processes` for the configured
 * monitoring connection.
 */
class DatabaseWatcher
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;

    private ?DatabaseInterface $connection = null;

    /** @var Closure(string, DatabaseConfig): DatabaseInterface */
    private Closure $connector;

    /**
     * @param null|Closure(string, DatabaseConfig): DatabaseInterface $connector Opens a connection from DSN and config
     */
    public function __construct(
        private readonly ?DatabaseMonitoringConfig $config,
        private readonly DatabaseDriverFactory $drivers = new DatabaseDriverFactory(),
        ?Closure $connector = null,
    ) {
        $this->connector = $connector
            ?? static fn (string $dsn, DatabaseConfig $config): DatabaseInterface => new Database($dsn, $config->username, $config->password);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $config = $this->requireConfig();
        $driver = $this->driver($config);
        $status = $this->query($config, fn (DatabaseInterface $db) => $driver->overview($db));

        return [
            ...$this->envelope($config, $driver),
            'status' => $status->toArray(),
            'databases' => array_map(
                static fn (DatabaseConfig $database): array => ['name' => $database->database, 'description' => $database->description],
                $config->databases,
            ),
        ];
    }

    /**
     * @param array<array-key, mixed> $query Request query parameters
     *
     * @return array<string, mixed>
     */
    public function getProcesses(array $query): array
    {
        $config = $this->requireConfig();
        [$database, $activeOnly, $limit] = $this->parseProcessQuery($query, $config->schemaNames());

        $driver = $this->driver($config);
        [$rows, $visibility] = $this->query(
            $config,
            fn (DatabaseInterface $db) => [$driver->processes($db), $driver->visibility($db)],
        );

        return [
            ...$this->envelope($config, $driver),
            'visibility' => $visibility->value,
            ...ProcessList::build($rows, $config->schemaNames(), $database, $activeOnly, $limit),
        ];
    }

    private function requireConfig(): DatabaseMonitoringConfig
    {
        return $this->config ?? throw DatabaseWatcherException::notConfigured();
    }

    private function driver(DatabaseMonitoringConfig $config): DatabaseDriverInterface
    {
        return $this->drivers->create($config->connection->engine);
    }

    /**
     * @template T
     *
     * @param Closure(DatabaseInterface): T $read
     *
     * @return T
     */
    private function query(DatabaseMonitoringConfig $config, Closure $read): mixed
    {
        $connection = $config->connection;

        if ($this->connection === null) {
            try {
                $this->connection = ($this->connector)($this->driver($config)->dsn($connection), $connection);
            } catch (PDOException $error) {
                throw DatabaseWatcherException::connectionFailed($connection->name, $connection->host, $connection->port, $error->getMessage());
            }
        }

        try {
            return $read($this->connection);
        } catch (PDOException|UnexpectedValueException $error) {
            throw DatabaseWatcherException::queryFailed($connection->name, $error->getMessage());
        }
    }

    /**
     * @return array{engine: string, instance: string, collected_at: string}
     */
    private function envelope(DatabaseMonitoringConfig $config, DatabaseDriverInterface $driver): array
    {
        return [
            'engine' => $driver->engine(),
            'instance' => $config->instance,
            'collected_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<array-key, mixed> $query
     * @param list<string>            $schemas
     *
     * @return array{0: null|string, 1: bool, 2: int}
     */
    private function parseProcessQuery(array $query, array $schemas): array
    {
        $database = $query['database'] ?? null;
        if ($database !== null && !is_string($database)) {
            throw DatabaseWatcherException::invalidParameter('database', 'must be a single value');
        }
        if ($database !== null && !in_array($database, $schemas, true)) {
            throw DatabaseWatcherException::unknownDatabase($database, $schemas);
        }

        $activeOnly = $query['active_only'] ?? '1';
        if ($activeOnly !== '0' && $activeOnly !== '1') {
            throw DatabaseWatcherException::invalidParameter('active_only', 'must be 0 or 1');
        }

        $limit = $query['limit'] ?? (string) self::DEFAULT_LIMIT;
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT) {
            throw DatabaseWatcherException::invalidParameter('limit', 'must be an integer from 1 to ' . self::MAX_LIMIT);
        }

        return [$database, $activeOnly === '1', (int) $limit];
    }
}
