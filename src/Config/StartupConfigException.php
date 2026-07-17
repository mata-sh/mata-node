<?php

declare(strict_types=1);

namespace Mata\Config;

use RuntimeException;
use Throwable;

/**
 * Thrown when startup configuration is missing or invalid.
 *
 * Shared by the startup config workflow (StartupConfigManager) and the JSON
 * config loader (JsonConfigLoader); the front controller maps it to a 503
 * response.
 */
final class StartupConfigException extends RuntimeException
{
    /**
     * @param list<string>         $missingFiles
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $message,
        private readonly StartupFailureReason $reason,
        private readonly array $missingFiles = [],
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Required config files are absent (possibly just created from templates
     * and awaiting user configuration).
     *
     * @param list<string>         $missingFiles
     * @param array<string, mixed> $context
     */
    public static function missing(string $message, array $missingFiles, array $context = []): self
    {
        return new self(
            $message,
            StartupFailureReason::ConfigurationMissing,
            missingFiles: $missingFiles,
            context: $context,
        );
    }

    /**
     * Config files exist but could not be loaded or validated.
     *
     * @param array<string, mixed> $context
     */
    public static function invalid(string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            $message,
            StartupFailureReason::ConfigurationInvalid,
            context: $context,
            previous: $previous,
        );
    }

    public function getReason(): StartupFailureReason
    {
        return $this->reason;
    }

    /**
     * @return list<string>
     */
    public function getMissingFiles(): array
    {
        return $this->missingFiles;
    }

    /**
     * Additional context for logging.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
