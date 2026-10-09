<?php

declare(strict_types=1);

namespace Mata\Core;

use Mata\Config\Dto\DatabaseConfig;
use PDO;
use PDOException;

/**
 * Read-only MySQL/MariaDB connection for monitoring queries.
 *
 * PDO-based, with prepared statements and configuration via DatabaseConfig.
 */
class Database implements DatabaseInterface
{
    private PDO $pdo;

    /**
     * Initialize database connection with configuration.
     *
     * Creates PDO connection with secure defaults.
     *
     * @param DatabaseConfig $config Database configuration DTO
     *
     * @throws PDOException If database connection fails
     */
    public function __construct(DatabaseConfig $config)
    {
        $dsn = "mysql:host={$config->host}";
        if (!empty($config->port)) {
            $dsn .= ";port={$config->port}";
        }
        $dsn .= ";dbname={$config->database};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $config->username, $config->password, [
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
