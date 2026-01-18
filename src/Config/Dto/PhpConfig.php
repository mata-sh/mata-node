<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing PHP runtime configuration.
 *
 * Contains PHP-specific settings including error display and logging levels.
 */
final class PhpConfig
{
    public function __construct(
        public readonly int $displayErrors,
        public readonly string $logLevel,
    ) {}
}
