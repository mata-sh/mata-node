<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/**
 * Immutable value object containing the result of successful startup
 * configuration initialization: the validated env config and the parsed
 * JSON config files.
 */
final class StartupConfigResult
{
    /**
     * @param EnvConfig                                              $envConfig   Application configuration loaded and validated from env files
     * @param array<string, array<mixed>>                            $jsonConfigs Parsed JSON configs keyed by filename
     * @param array<int, array{identifier: string, message: string}> $warnings    Non-critical validation warnings
     */
    public function __construct(
        public readonly EnvConfig $envConfig,
        private readonly array $jsonConfigs = [],
        public readonly array $warnings = []
    ) {}

    /**
     * @return array<mixed>
     */
    public function jsonConfig(string $filename): array
    {
        return $this->jsonConfigs[$filename]
            ?? throw new InvalidArgumentException("JSON config was not loaded at startup: {$filename}");
    }
}
