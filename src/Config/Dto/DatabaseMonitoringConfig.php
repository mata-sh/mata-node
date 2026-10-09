<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Database monitoring settings from the `monitoring` block of `databases.json`.
 */
final class DatabaseMonitoringConfig
{
    /**
     * @param string               $instance   Non-secret label returned in responses
     * @param DatabaseConfig       $connection Connection used for status and process queries
     * @param list<DatabaseConfig> $databases  Monitored databases; their schema names form the allowlist
     */
    public function __construct(
        public readonly string $instance,
        public readonly DatabaseConfig $connection,
        public readonly array $databases,
    ) {}

    /**
     * @return list<string> Unique schema names in configuration order
     */
    public function schemaNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (DatabaseConfig $database): string => $database->database,
            $this->databases,
        )));
    }
}
