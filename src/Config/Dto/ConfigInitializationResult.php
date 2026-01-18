<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable value object containing the result of successful configuration initialization.
 */
final class ConfigInitializationResult
{
    /**
     * @param ApplicationConfig                                      $config   Loaded and validated application configuration
     * @param array<int, array{identifier: string, message: string}> $warnings Non-critical validation warnings
     */
    public function __construct(
        public readonly ApplicationConfig $config,
        public readonly array $warnings = []
    ) {}
}
