<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing database connection configuration.
 *
 * Contains all necessary credentials and connection information
 * for establishing a database connection.
 */
final class DatabaseConfig
{
    public function __construct(
        public readonly string $host,
        public readonly string $username,
        public readonly string $password,
        public readonly string $database,
        public readonly string $port,
        public readonly string $description
    ) {}
}
