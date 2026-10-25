<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/** Database monitoring settings from `databases.json`. */
final class DatabaseMonitoringConfig
{
    /**
     * @param non-empty-array<array-key, DatabaseConnectionConfig> $connections Stable name => monitoring settings
     */
    public function __construct(
        public readonly array $connections,
    ) {}
}
