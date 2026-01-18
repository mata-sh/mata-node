<?php

declare(strict_types=1);

namespace Mata\Config;

use Exception;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Dto\ConfigInitializationResult;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates environment configuration initialization.
 *
 * Handles detection, creation, loading, and validation of configuration files
 * in a single, declarative workflow.
 */
final class EnvConfigManager
{
    private readonly EnvConfigCreator $creator;
    private readonly EnvConfigLoader $loader;

    /**
     * @param string                $configDir     Configuration directory path
     * @param string                $exampleDir    Template directory path
     * @param array<string>         $requiredFiles List of required config filenames
     * @param LoggerInterface       $logger        Logger for diagnostics
     * @param null|EnvConfigCreator $creator       Optional creator instance (creates internally if null)
     * @param null|EnvConfigLoader  $loader        Optional loader instance (creates internally if null)
     */
    public function __construct(
        private readonly string $configDir,
        private readonly string $exampleDir,
        private readonly array $requiredFiles,
        private readonly LoggerInterface $logger,
        ?EnvConfigCreator $creator = null,
        ?EnvConfigLoader $loader = null
    ) {
        $this->creator = $creator ?? new EnvConfigCreator();
        $this->loader = $loader ?? new EnvConfigLoader();
    }

    /**
     * Initialize configuration from environment files.
     *
     * Orchestrates the complete configuration workflow:
     * 1. Detect missing config files
     * 2. Create missing files from templates (then exit for user setup)
     * 3. Load configuration from files
     * 4. Validate configuration
     * 5. Return config and warnings
     *
     * @return ConfigInitializationResult Loaded config and validation warnings
     *
     * @throws EnvConfigFatalException When setup required, creation failed, loading failed, or validation failed
     */
    public function initialize(): ConfigInitializationResult
    {
        // Phase 1: Check for missing files
        $missingFiles = $this->detectMissingFiles();

        if (!empty($missingFiles)) {
            $this->handleMissingFiles($missingFiles);
        }

        // Phase 2: Load configuration
        $config = $this->loadConfiguration();

        // Phase 3: Validate configuration
        $validationResult = $this->validateConfiguration($config);

        // Phase 4: Return result with warnings
        return new ConfigInitializationResult(
            config: $config,
            warnings: $validationResult['warnings'] ?? []
        );
    }

    /**
     * Detect which required config files are missing.
     *
     * @return array<string> List of missing file names
     */
    private function detectMissingFiles(): array
    {
        try {
            return $this->creator->getMissingFiles($this->requiredFiles, $this->configDir);
        } catch (Exception $e) {
            $this->logger->error('Failed to detect missing config files: ' . $e->getMessage());

            throw  EnvConfigFatalException::loadFailed(
                message: 'Failed to check configuration files: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Attempt to create missing files from templates, then throw exception.
     *
     * This method ALWAYS throws an exception, either:
     * - setupRequired() if files were created successfully (user needs to configure)
     * - setupFailed() if file creation failed
     *
     * @param array<string> $missingFiles List of missing file names
     *
     * @throws EnvConfigFatalException Always throws (either setupRequired or setupFailed)
     */
    private function handleMissingFiles(array $missingFiles): never
    {
        // Log missing files
        foreach ($missingFiles as $file) {
            $this->logger->critical("Missing config file: {$this->configDir}/{$file}");
        }

        // Attempt to create from templates
        $this->logger->info('Attempting to create missing config files from templates...');

        try {
            $results = $this->creator->createFromTemplates(
                $missingFiles,
                $this->configDir,
                $this->exampleDir
            );

            $created = array_filter($results);
            $failed = array_diff_key($results, $created);

            // Log creation results
            if (!empty($created)) {
                $this->logger->info('Created config files from templates: ' . implode(', ', array_keys($created)));
            }

            if (!empty($failed)) {
                $this->logger->error('Failed to create config files: ' . implode(', ', array_keys($failed)));

                throw  EnvConfigFatalException::setupFailed(
                    array_keys($failed),
                    $this->exampleDir
                );
            }

            // All files created successfully - tell user to configure them
            $this->logger->info('All missing config files created successfully. Please review and update them with your settings.');

            throw  EnvConfigFatalException::setupRequired(array_keys($created));
        } catch (EnvConfigFatalException $e) {
            // Re-throw our own exceptions
            throw $e;
        } catch (Exception $e) {
            // Wrap other exceptions
            $this->logger->error('Unexpected error during config file creation: ' . $e->getMessage());

            throw  EnvConfigFatalException::setupFailed(
                $missingFiles,
                $this->exampleDir
            );
        }
    }

    /**
     * Load configuration from environment files.
     *
     * @return ApplicationConfig Loaded application configuration
     *
     * @throws EnvConfigFatalException If loading fails
     */
    private function loadConfiguration(): ApplicationConfig
    {
        try {
            return $this->loader->load($this->requiredFiles, $this->configDir);
        } catch (Exception $e) {
            $this->logger->critical('Configuration loading failed: ' . $e->getMessage());

            throw  EnvConfigFatalException::loadFailed(
                message: $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Validate loaded configuration.
     *
     * @param ApplicationConfig $config Configuration to validate
     *
     * @return array<string, mixed> Validation result with 'is_valid', 'critical_errors', 'warnings'
     *
     * @throws EnvConfigFatalException If critical validation errors exist
     */
    private function validateConfiguration(ApplicationConfig $config): array
    {
        $validationResult = $this->loader->validateConfig($config);

        // Log critical errors
        if (!$validationResult['is_valid']) {
            foreach ($validationResult['critical_errors'] as $error) {
                $this->logger->critical("[{$error['identifier']}] {$error['message']}");
            }

            throw  EnvConfigFatalException::validationFailed($validationResult['critical_errors']);
        }

        // Log warnings (non-critical)
        foreach ($validationResult['warnings'] ?? [] as $warning) {
            $this->logger->warning("[{$warning['identifier']}] {$warning['message']}");
        }

        return $validationResult;
    }
}
