<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Core\DatabaseInterface;
use UnexpectedValueException;

/**
 * MariaDB and MySQL driver.
 */
final class MariaDbDriver implements DatabaseDriverInterface
{
    private const STATUS_VARIABLES = [
        'Threads_connected',
        'Threads_running',
        'Max_used_connections',
        'Connection_errors_max_connections',
        'Aborted_connects',
        'Uptime',
    ];

    public function engine(): string
    {
        return 'mariadb';
    }

    public function dsn(DatabaseConfig $config): string
    {
        $dsn = "mysql:host={$config->host}";
        if ($config->port !== '') {
            $dsn .= ";port={$config->port}";
        }

        return $dsn . ";dbname={$config->database};charset=utf8mb4";
    }

    public function overview(DatabaseInterface $db): OverviewStatus
    {
        $variables = $this->keyValueRows($db->select("SHOW GLOBAL VARIABLES WHERE Variable_name = 'max_connections'"));
        $placeholders = implode(', ', array_fill(0, count(self::STATUS_VARIABLES), '?'));
        $status = $this->keyValueRows($db->select("SHOW GLOBAL STATUS WHERE Variable_name IN ({$placeholders})", self::STATUS_VARIABLES));

        return new OverviewStatus(
            maxConnections: $this->number($variables, 'max_connections'),
            connections: $this->number($status, 'threads_connected'),
            running: $this->number($status, 'threads_running'),
            maxUsedConnections: $this->number($status, 'max_used_connections'),
            refusedConnections: $this->number($status, 'connection_errors_max_connections'),
            abortedConnects: $this->number($status, 'aborted_connects'),
            uptimeSeconds: $this->number($status, 'uptime'),
        );
    }

    public function processes(DatabaseInterface $db): array
    {
        $rows = $db->select(<<<'SQL'
            SELECT ID AS id, USER AS user, HOST AS host, DB AS db, COMMAND AS command,
                   TIME AS time, STATE AS state, INFO AS info
            FROM information_schema.processlist
            WHERE ID <> CONNECTION_ID()
        SQL);

        return array_map(static function (array $row): ProcessRow {
            $command = self::text($row['command'] ?? null);

            return new ProcessRow(
                id: (int) ($row['id'] ?? 0),
                user: self::text($row['user'] ?? null),
                host: self::text($row['host'] ?? null),
                database: self::text($row['db'] ?? null),
                command: $command,
                timeSeconds: (int) ($row['time'] ?? 0),
                state: self::text($row['state'] ?? null),
                statement: ProcessRow::statementKeyword(self::text($row['info'] ?? null)),
                running: $command !== null && $command !== 'Sleep',
            );
        }, $rows);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Checks the PROCESS privilege of the account itself. Privileges granted only
     * through a role are not listed in USER_PRIVILEGES and report `own_sessions`.
     */
    public function visibility(DatabaseInterface $db): Visibility
    {
        $rows = $db->select(<<<'SQL'
            SELECT COUNT(*) AS granted
            FROM information_schema.USER_PRIVILEGES
            WHERE PRIVILEGE_TYPE = 'PROCESS'
              AND GRANTEE = CONCAT(
                  '''', SUBSTRING(CURRENT_USER(), 1, CHAR_LENGTH(CURRENT_USER()) - CHAR_LENGTH(SUBSTRING_INDEX(CURRENT_USER(), '@', -1)) - 1),
                  '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), ''''
              )
        SQL);

        return (int) ($rows[0]['granted'] ?? 0) > 0 ? Visibility::AllSessions : Visibility::OwnSessions;
    }

    /**
     * @param list<array<string, mixed>> $rows `SHOW VARIABLES`/`SHOW STATUS` rows
     *
     * @return array<string, mixed> Lowercased variable name => value
     */
    private function keyValueRows(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $name = $row['Variable_name'] ?? null;
            if (is_string($name)) {
                $values[strtolower($name)] = $row['Value'] ?? null;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function number(array $values, string $name): int
    {
        $value = $values[$name] ?? null;
        if (!is_string($value) && !is_int($value) || !ctype_digit((string) $value)) {
            throw new UnexpectedValueException("MariaDB status value '{$name}' is missing or not a non-negative integer");
        }

        return (int) $value;
    }
}
