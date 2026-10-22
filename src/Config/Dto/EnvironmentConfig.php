<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing environment configuration.
 *
 * Contains the runtime environment and the temp directory.
 */
final class EnvironmentConfig
{
    public function __construct(
        public readonly string $env,
        public readonly string $tempDir,
    ) {}
}
