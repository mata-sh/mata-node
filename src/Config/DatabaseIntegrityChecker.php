<?php

declare(strict_types=1);

namespace Mata\Config;

use Mata\Core\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Database integrity checker.
 *
 * Provides lightweight verification that critical database artifacts
 * exist after migrations have been applied. This is a read-only sanity
 * check that can be used during deployments or troubleshooting to
 * confirm that required tables are present.
 */
class DatabaseIntegrityChecker
{
    private DatabaseInterface $db;

    /** @var array<int,string> */
    private array $requiredTables = [
        'servers',
        'config_sync_tracking',
        'events',
        'alarms',
        'notifications',
        'phinxlog',
    ];

    /**
     * Initialize integrity checker with database connection.
     */
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Run integrity checks and return the aggregated result.
     *
     * @return array{
     *     status: 'fail'|'ok',
     *     checks: array<int,array{label:string,status:'missing'|'ok',details?:string}>
     * }
     */
    public function run(): array
    {
        $checks = [];

        foreach ($this->requiredTables as $table) {
            $checks[] = $this->checkTableExists($table);
        }

        $status = array_reduce(
            $checks,
            static fn (string $carry, array $check): string => $carry === 'ok' && $check['status'] === 'ok' ? 'ok' : 'fail',
            'ok'
        );

        return [
            'status' => $status,
            'checks' => $checks,
        ];
    }

    /**
     * Allow consumers to override the list of required tables.
     *
     * @param array<int,string> $tables
     */
    public function setRequiredTables(array $tables): void
    {
        $this->requiredTables = $tables;
    }

    /**
     * Perform a lightweight readiness probe.
     *
     * Ensures the connection can execute simple queries and that at least the
     * first required table exists. Meant to be cheap enough for bootstrap usage.
     *
     * @throws RuntimeException When the probe fails
     */
    public function assertReady(): void
    {
        try {
            $this->db->runQuery('SELECT 1');
        } catch (Throwable $exception) {
            throw new RuntimeException('Database connection check failed.', 0, $exception);
        }

        $referenceTable = $this->requiredTables[0] ?? null;
        if ($referenceTable === null) {
            return;
        }

        try {
            $result = $this->db->runQuery('SHOW TABLES LIKE ?', [$referenceTable]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Database schema check failed.', 0, $exception);
        }

        if (!is_array($result) || count($result) === 0) {
            throw new RuntimeException(sprintf('Reference table "%s" missing.', $referenceTable));
        }
    }

    /**
     * Perform a single table existence check.
     *
     * @return array{label:string,status:'missing'|'ok',details?:string}
     */
    private function checkTableExists(string $table): array
    {
        $result = $this->db->runQuery('SHOW TABLES LIKE ?', [$table]);
        $exists = is_array($result) && count($result) > 0;

        return [
            'label' => "table:{$table}",
            'status' => $exists ? 'ok' : 'missing',
            'details' => $exists ? null : 'Required table not found; ensure migrations have been run.',
        ];
    }
}
