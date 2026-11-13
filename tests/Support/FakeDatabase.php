<?php

declare(strict_types=1);

namespace Mata\Tests\Support;

use Mata\Core\DatabaseInterface;
use PDOException;

/**
 * Returns canned rows for the first registered SQL fragment found in a query.
 */
final class FakeDatabase implements DatabaseInterface
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, list<array<string, mixed>>|PDOException> $results SQL fragment => rows or exception
     */
    public function __construct(private array $results = []) {}

    public function select(string $sql, array $params = []): array
    {
        $this->queries[] = $sql;

        foreach ($this->results as $fragment => $result) {
            if (str_contains($sql, $fragment)) {
                if ($result instanceof PDOException) {
                    throw $result;
                }

                return $result;
            }
        }

        return [];
    }
}
