<?php

declare(strict_types=1);

namespace Mata\Core;

use PDO;
use PDOException;

/**
 * Read-only PDO connection for monitoring queries.
 */
class Database implements DatabaseInterface
{
    private PDO $pdo;

    /**
     * @param string $dsn PDO DSN built by the engine driver
     *
     * @throws PDOException If the connection fails
     */
    public function __construct(string $dsn, string $username, string $password)
    {
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Run a read query with prepared statement parameters.
     *
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return list<array<string,mixed>> Result rows
     *
     * @throws PDOException If query execution fails
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
