<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO for the database server login.
 */
final class DatabaseConfig
{
    public function __construct(
        public readonly string $engine,
        public readonly string $host,
        public readonly string $port,
        public readonly string $username,
        public readonly string $password,
    ) {}
}
