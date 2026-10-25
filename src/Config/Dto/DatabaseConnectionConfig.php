<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/** One independently monitored database server/account. */
final class DatabaseConnectionConfig
{
    /**
     * @param array<array-key, string> $databases Monitored database name => description
     */
    public function __construct(
        public readonly DatabaseConfig $connection,
        public readonly array $databases,
    ) {}

    /**
     * @return list<string>
     */
    public function databaseNames(): array
    {
        return array_map(strval(...), array_keys($this->databases));
    }
}
