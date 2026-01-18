<?php

declare(strict_types=1);

namespace Mata\Core;

/**
 * Common contract for database implementations used by the application.
 *
 * Allows swapping the concrete database connection while keeping consumers
 * agnostic of the actual implementation (e.g. real PDO-backed or null object).
 */
interface DatabaseInterface
{
    /**
     * Execute a SQL statement using prepared parameters.
     *
     * @param string                  $sql    SQL statement to execute
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return array<int,array<string,mixed>>|int Result set for SELECT queries,
     *                                            insert ID for INSERT queries,
     *                                            affected rows for UPDATE/DELETE
     */
    public function runQuery(string $sql, array $params = []): array|int;

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the active transaction.
     */
    public function commit(): void;

    /**
     * Rollback the active transaction.
     */
    public function rollback(): void;
}
