<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing environment configuration.
 *
 * Contains fundamental environment settings including runtime environment,
 * domain, and directory path information.
 */
final class EnvironmentConfig
{
    public function __construct(
        public readonly string $env,
        public readonly string $domain,
        public readonly string $dir,
        public readonly string $tempDir,
    ) {}
}
