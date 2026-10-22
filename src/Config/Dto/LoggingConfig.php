<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use Monolog\Level;

/**
 * Immutable DTO representing logging configuration.
 *
 * Contains settings for application logging including log level and file location.
 */
final class LoggingConfig
{
    public function __construct(
        public readonly string $path,
        public readonly Level $level,
    ) {}
}
