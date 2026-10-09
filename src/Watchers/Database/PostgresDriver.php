<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Core\DatabaseInterface;
use UnexpectedValueException;

/**
 * PostgreSQL driver. Counts only client backends, not background workers.
 */
final class PostgresDriver implements DatabaseDriverInterface
{
    public function engine(): string
    {
        return 'pgsql';
    }

    /**
     * The config has no database field; PostgreSQL needs one to connect.
     */
    public function dsn(DatabaseConfig $config): string
    {
        $port = $config->port !== '' ? ";port={$config->port}" : '';

        return "pgsql:host={$config->host}{$port};dbname=postgres";
    }

    public function overview(DatabaseInterface $db): OverviewStatus
    {
        $settings = $db->select('SHOW max_connections')[0] ?? [];
        $activity = $db->select(<<<'SQL'
            SELECT count(*) AS connections,
                   count(*) FILTER (WHERE state = 'active') AS running,
                   extract(epoch from now() - pg_postmaster_start_time())::bigint AS uptime_seconds
            FROM pg_stat_activity
            WHERE backend_type = 'client backend'
        SQL)[0] ?? [];

        return new OverviewStatus(
            maxConnections: $this->number($settings, 'max_connections'),
            connections: $this->number($activity, 'connections'),
            running: $this->number($activity, 'running'),
            maxUsedConnections: null,
            refusedConnections: null,
            abortedConnects: null,
            uptimeSeconds: $this->number($activity, 'uptime_seconds'),
        );
    }

    public function processes(DatabaseInterface $db): array
    {
        $rows = $db->select(<<<'SQL'
            SELECT pid, usename, client_addr, client_port, datname, state, wait_event_type, wait_event, query,
                   coalesce(extract(epoch from now() - CASE WHEN state LIKE 'idle%' THEN state_change ELSE query_start END)::bigint, 0) AS time
            FROM pg_stat_activity
            WHERE backend_type = 'client backend'
              AND pid <> pg_backend_pid()
        SQL);

        return array_map(static function (array $row): ProcessRow {
            $state = self::text($row['state'] ?? null);
            $address = self::text($row['client_addr'] ?? null);
            $waitEvent = array_filter([self::text($row['wait_event_type'] ?? null), self::text($row['wait_event'] ?? null)]);

            return new ProcessRow(
                id: (int) ($row['pid'] ?? 0),
                user: self::text($row['usename'] ?? null),
                host: $address !== null ? $address . ':' . (int) ($row['client_port'] ?? 0) : null,
                database: self::text($row['datname'] ?? null),
                command: $state,
                timeSeconds: (int) ($row['time'] ?? 0),
                state: $waitEvent !== [] ? implode('/', $waitEvent) : null,
                statement: ProcessRow::statementKeyword(self::text($row['query'] ?? null)),
                running: $state === 'active',
            );
        }, $rows);
    }

    public function visibility(DatabaseInterface $db): Visibility
    {
        $rows = $db->select(<<<'SQL'
            SELECT current_setting('is_superuser') = 'on'
                   OR pg_has_role(current_user, 'pg_read_all_stats', 'MEMBER') AS granted
        SQL);

        return in_array($rows[0]['granted'] ?? false, [true, 't', 1, '1'], true) ? Visibility::AllSessions : Visibility::OwnSessions;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function number(array $values, string $name): int
    {
        $value = $values[$name] ?? null;
        if (!is_string($value) && !is_int($value) || !ctype_digit((string) $value)) {
            throw new UnexpectedValueException("PostgreSQL status value '{$name}' is missing or not a non-negative integer");
        }

        return (int) $value;
    }
}
