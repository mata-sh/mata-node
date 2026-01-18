<?php

declare(strict_types=1);

namespace Mata\Config;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when configuration initialization encounters an unrecoverable error
 * that should halt application startup.
 */
final class EnvConfigFatalException extends RuntimeException
{
    private string $userMessage;
    private string $title;
    private string $heading;
    private string $type;
    private array $context;
    private ?string $documentationUrl;

    /**
     * @param string         $message          Technical message for logging
     * @param string         $userMessage      User-friendly message for display
     * @param string         $title            Page title for display
     * @param string         $heading          Heading for display
     * @param string         $type             Display type ('warning', 'error', 'info')
     * @param array          $context          Additional context for logging
     * @param null|string    $documentationUrl URL to relevant documentation
     * @param int            $code             Exception code
     * @param null|Throwable $previous         Previous exception
     */
    public function __construct(
        string $message,
        string $userMessage,
        string $title,
        string $heading,
        string $type = 'error',
        array $context = [],
        ?string $documentationUrl = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->userMessage = $userMessage;
        $this->title = $title;
        $this->heading = $heading;
        $this->type = $type;
        $this->context = $context;
        $this->documentationUrl = $documentationUrl;
    }

    /**
     * Get user-friendly error message suitable for display to end users.
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getHeading(): string
    {
        return $this->heading;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get additional context for logging.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get documentation URL for troubleshooting help.
     */
    public function getDocumentationUrl(): ?string
    {
        return $this->documentationUrl;
    }

    /**
     * Create exception when config files were created and need user configuration.
     *
     * @param array<string> $createdFiles     List of config files that were created
     * @param null|string   $documentationUrl URL to setup documentation
     */
    public static function setupRequired(array $createdFiles, ?string $documentationUrl = null): self
    {
        return new self(
            message: 'Configuration files created from templates; user setup required',
            userMessage: 'Configuration files have been created successfully from templates. Please review and update them, then restart the application.',
            title: 'Configuration Setup Required',
            heading: 'Configuration Setup Required',
            type: 'warning',
            context: ['created_files' => $createdFiles],
            documentationUrl: $documentationUrl
        );
    }

    /**
     * Create exception when config file creation failed.
     *
     * @param array<string> $failedFiles      List of files that could not be created
     * @param string        $exampleDir       Path to example template directory
     * @param null|string   $documentationUrl URL to setup documentation
     */
    public static function setupFailed(array $failedFiles, string $exampleDir, ?string $documentationUrl = null): self
    {
        return new self(
            message: 'Failed to create required configuration files',
            userMessage: 'The application could not automatically create the required configuration files.',
            title: 'Configuration Error',
            heading: 'Critical Configuration Error',
            type: 'error',
            context: [
                'failed_files' => $failedFiles,
                'example_dir' => $exampleDir,
            ],
            documentationUrl: $documentationUrl
        );
    }

    /**
     * Create exception when config validation failed with critical errors.
     *
     * @param array<int, array{identifier: string, message: string}> $errors           Critical validation errors
     * @param null|string                                            $documentationUrl URL to validation documentation
     */
    public static function validationFailed(array $errors, ?string $documentationUrl = null): self
    {
        return new self(
            message: 'Configuration validation failed with critical errors',
            userMessage: 'Critical configuration errors were detected that prevent the application from starting.',
            title: 'Configuration Validation Failed',
            heading: 'Configuration Validation Failed',
            type: 'error',
            context: ['validation_errors' => $errors],
            documentationUrl: $documentationUrl
        );
    }

    /**
     * Create exception when config loading failed.
     *
     * @param string         $message          Descriptive error message
     * @param null|Throwable $previous         Original exception that caused the failure
     * @param null|string    $documentationUrl URL to troubleshooting documentation
     */
    public static function loadFailed(string $message, ?Throwable $previous = null, ?string $documentationUrl = null): self
    {
        return new self(
            message: "Configuration loading failed: {$message}",
            userMessage: 'The application failed to initialize due to a configuration loading error.',
            title: 'Application Initialization Failed',
            heading: 'Application Initialization Failed',
            type: 'error',
            context: [
                'load_error' => $message,
                'original_error' => $previous?->getMessage(),
                'original_exception' => $previous ? get_class($previous) : null,
            ],
            documentationUrl: $documentationUrl,
            previous: $previous
        );
    }
}
