<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

use Mata\Config\Dto\DatabaseConnectionConfig;
use Mata\Config\Dto\DatabaseMonitoringConfig;
use Mata\Watchers\Exceptions\DatabaseWatcherException;

/**
 * Validated query parameters of `/databases/processes`.
 */
final class ProcessRequest
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;

    private function __construct(
        public readonly string $connection,
        public readonly DatabaseConnectionConfig $config,
        public readonly ?string $database,
        public readonly bool $activeOnly,
        public readonly int $limit,
    ) {}

    /**
     * @param array<array-key, mixed> $params `$_GET`: `connection` (required), `database`,
     *                                        `active_only` (0|1, default 1), `limit` (1–100, default 50)
     *
     * @throws DatabaseWatcherException 400 for a malformed parameter, 404 for an unknown connection or database
     */
    public static function fromQuery(array $params, DatabaseMonitoringConfig $config): self
    {
        $connection = $params['connection'] ?? null;
        if (!is_string($connection)) {
            throw DatabaseWatcherException::invalidParameter('connection', 'is required and must be a single value');
        }
        if (!array_key_exists($connection, $config->connections)) {
            throw DatabaseWatcherException::unknownConnection($connection);
        }
        $connectionConfig = $config->connections[$connection];

        $database = $params['database'] ?? null;
        if ($database !== null && !is_string($database)) {
            throw DatabaseWatcherException::invalidParameter('database', 'must be a single value');
        }
        $databases = $connectionConfig->databaseNames();
        if ($database !== null && !in_array($database, $databases, true)) {
            throw DatabaseWatcherException::unknownDatabase($database, $databases);
        }

        $activeOnly = $params['active_only'] ?? '1';
        if ($activeOnly !== '0' && $activeOnly !== '1') {
            throw DatabaseWatcherException::invalidParameter('active_only', 'must be 0 or 1');
        }

        $limit = $params['limit'] ?? (string) self::DEFAULT_LIMIT;
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT) {
            throw DatabaseWatcherException::invalidParameter('limit', 'must be an integer from 1 to ' . self::MAX_LIMIT);
        }

        return new self($connection, $connectionConfig, $database, $activeOnly === '1', (int) $limit);
    }
}
