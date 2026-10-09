<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Database monitoring settings from `databases.json`.
 */
final class DatabaseMonitoringConfig
{
    /**
     * @param DatabaseConfig           $connection Login used for status and process queries
     * @param array<array-key, string> $databases  Monitored database name => description
     */
    public function __construct(
        public readonly DatabaseConfig $connection,
        public readonly array $databases,
    ) {}

    /**
     * @return list<string> Monitored database names in configuration order
     */
    public function databaseNames(): array
    {
        return array_map(strval(...), array_keys($this->databases));
    }
}
