<?php

declare(strict_types=1);

namespace Mata\Core;

/**
 * Read-only database contract for monitoring queries.
 *
 * Allows swapping the concrete database connection while keeping consumers
 * agnostic of the actual implementation.
 */
interface DatabaseInterface
{
    /**
     * Run a read query (SELECT, SHOW, ...) with prepared parameters.
     *
     * @param string                  $sql    SQL statement to execute
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return list<array<string,mixed>> Result rows
     */
    public function select(string $sql, array $params = []): array;
}
