<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing one configured database connection.
 */
final class DatabaseConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $engine,
        public readonly string $host,
        public readonly string $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $database,
        public readonly string $description = '',
    ) {}
}
