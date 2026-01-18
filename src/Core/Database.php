<?php

declare(strict_types=1);

namespace Mata\Core;

use Mata\Config\Dto\DatabaseConfig;
use PDO;
use PDOException;

/**
 * MySQL database connection wrapper with prepared statements.
 *
 * Provides secure database operations with:
 * - PDO-based connection management
 * - Prepared statement support for SQL injection prevention
 * - Transaction support for data integrity
 * - Type-safe configuration via DatabaseConfig DTO
 * - Consistent error handling
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
        // if (class_exists(Logger::class)) {
        //     $this->logger = Logger::getInstance();
        // }
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
     * Execute SQL query with prepared statement parameters.
     *
     * Handles both SELECT and INSERT/UPDATE/DELETE operations.
     *
     * @param array<int|string,mixed> $params Parameters for prepared statement
     *
     * @return array<int,array<string,mixed>>|int Result array for SELECT, insert ID for INSERT, affected rows for UPDATE/DELETE
     *
     * @throws PDOException If query execution fails
     */
    public function runQuery(string $sql, array $params = []): array|int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $firstWord = strtok(trim($sql), ' ');
        $sqlType = strtoupper($firstWord === false ? '' : $firstWord);

        return match ($sqlType) {
            'INSERT' => (int) $this->pdo->lastInsertId(),
            'UPDATE', 'DELETE' => $stmt->rowCount(),
            default => $stmt->fetchAll(PDO::FETCH_ASSOC),
        };
    }

    /**
     * Begin database transaction for atomic operations.
     *
     * @throws PDOException If transaction cannot be started
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commit current database transaction.
     *
     * @throws PDOException If commit fails
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rollback current database transaction.
     *
     * @throws PDOException If rollback fails
     */
    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Get the underlying PDO connection instance.
     *
     * @return PDO Raw PDO connection for advanced operations
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
