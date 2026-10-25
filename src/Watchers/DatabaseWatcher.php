<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\DatabaseConnectionConfig;
use Mata\Config\Dto\DatabaseMonitoringConfig;
use Mata\Core\Database;
use Mata\Core\DatabaseInterface;
use Mata\Watchers\Database\DatabaseDriverFactory;
use Mata\Watchers\Database\DatabaseDriverInterface;
use Mata\Watchers\Database\ProcessList;
use Mata\Watchers\Database\ProcessRequest;
use Mata\Watchers\Exceptions\DatabaseWatcherException;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * Serves `/databases/overview` and `/databases/processes` for the configured
 * monitoring connections.
 */
class DatabaseWatcher
{
    /** @var array<array-key, DatabaseInterface> */
    private array $connections = [];

    /** @var Closure(string, DatabaseConfig): DatabaseInterface */
    private Closure $connector;

    /**
     * @param null|Closure(string, DatabaseConfig): DatabaseInterface $connector Opens a connection from DSN and config
     */
    public function __construct(
        private readonly ?DatabaseMonitoringConfig $config,
        private readonly DatabaseDriverFactory $drivers = new DatabaseDriverFactory(),
        ?Closure $connector = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->connector = $connector
            ?? static fn (string $dsn, DatabaseConfig $config): DatabaseInterface => new Database($dsn, $config->username, $config->password);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $connections = [];
        foreach ($this->requireConfig()->connections as $name => $config) {
            $name = (string) $name;
            $driver = $this->driver($config);
            $status = null;
            $error = null;

            try {
                $status = $this->query($name, $config, fn (DatabaseInterface $db) => $driver->overview($db))->toArray();
            } catch (DatabaseWatcherException) {
                $error = ['status_code' => 503, 'message' => 'Database connection failed'];
            }
            $connections[] = [
                'connection' => $name,
                ...$this->envelope($driver),
                'status' => $status,
                'databases' => $this->databaseList($config),
                'error' => $error,
            ];
        }

        return ['connections' => $connections];
    }

    /**
     * @param array<array-key, mixed> $params Query parameters, see {@see ProcessRequest::fromQuery()}
     *
     * @return array<string, mixed>
     */
    public function getProcesses(array $params): array
    {
        $request = ProcessRequest::fromQuery($params, $this->requireConfig());

        $driver = $this->driver($request->config);
        [$rows, $visibility] = $this->query(
            $request->connection,
            $request->config,
            fn (DatabaseInterface $db) => [$driver->processes($db), $driver->visibility($db)],
        );

        return [
            'connection' => $request->connection,
            ...$this->envelope($driver),
            'visibility' => $visibility->value,
            ...ProcessList::build($rows, $request->config->databaseNames(), $request->database, $request->activeOnly, $request->limit),
        ];
    }

    private function requireConfig(): DatabaseMonitoringConfig
    {
        return $this->config ?? throw DatabaseWatcherException::notConfigured();
    }

    private function driver(DatabaseConnectionConfig $config): DatabaseDriverInterface
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
    private function query(string $name, DatabaseConnectionConfig $config, Closure $read): mixed
    {
        $connection = $config->connection;

        try {
            $this->connections[$name] ??= ($this->connector)($this->driver($config)->dsn($connection), $connection);

            return $read($this->connections[$name]);
        } catch (PDOException|UnexpectedValueException $error) {
            // The response stays generic; the reason (refused login, host down) only goes to the node log.
            $this->logger->warning('Database connection failed', [
                'connection' => $name,
                'host' => $connection->host,
                'port' => $connection->port,
                'reason' => $error->getMessage(),
            ]);

            throw DatabaseWatcherException::connectionFailed();
        }
    }

    /**
     * @return array{engine: string, collected_at: string}
     */
    private function envelope(DatabaseDriverInterface $driver): array
    {
        return [
            'engine' => $driver->engine(),
            'collected_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    private function databaseList(DatabaseConnectionConfig $config): array
    {
        $list = [];
        foreach ($config->databases as $name => $description) {
            // Numeric names become integer keys when JSON is decoded to PHP arrays.
            $list[] = ['name' => (string) $name, 'description' => $description];
        }

        return $list;
    }
}
