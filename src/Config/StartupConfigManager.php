<?php

declare(strict_types=1);

namespace Mata\Config;

use Exception;
use Mata\Config\Dto\EnvConfig;
use Mata\Config\Dto\StartupConfigResult;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates startup configuration initialization.
 *
 * Handles detection, creation, loading, and validation of configuration files
 * in a single, declarative workflow.
 */
final class StartupConfigManager
{
    /**
     * All config filenames that must exist at startup (env + JSON files);
     * missing ones are created from templates.
     *
     * @var array<string>
     */
    private readonly array $requiredFiles;

    private readonly EnvConfigCreator $creator;
    private readonly EnvConfigLoader $envLoader;
    private readonly JsonConfigLoader $jsonLoader;

    /**
     * @param string                $configDir  Configuration directory path
     * @param string                $exampleDir Template directory path
     * @param array<string>         $envFiles   Dotenv files loaded into the application config
     * @param array<string>         $jsonFiles  JSON files parsed into the result
     * @param LoggerInterface       $logger     Logger for diagnostics
     * @param null|EnvConfigCreator $creator    Optional creator instance (creates internally if null)
     * @param null|EnvConfigLoader  $envLoader  Optional env loader instance (creates internally if null)
     * @param null|JsonConfigLoader $jsonLoader Optional JSON loader instance (creates internally if null)
     */
    public function __construct(
        private readonly string $configDir,
        private readonly string $exampleDir,
        private readonly array $envFiles,
        private readonly array $jsonFiles,
        private readonly LoggerInterface $logger,
        ?EnvConfigCreator $creator = null,
        ?EnvConfigLoader $envLoader = null,
        ?JsonConfigLoader $jsonLoader = null
    ) {
        $this->requiredFiles = [...$envFiles, ...$jsonFiles];
        $this->creator = $creator ?? new EnvConfigCreator();
        $this->envLoader = $envLoader ?? new EnvConfigLoader();
        $this->jsonLoader = $jsonLoader ?? new JsonConfigLoader();
    }

    /**
     * Initialize configuration from environment and JSON files.
     *
     * Orchestrates the complete configuration workflow:
     * 1. Detect missing config files
     * 2. Create missing files from templates (then exit for user setup)
     * 3. Load env configuration
     * 4. Validate env configuration
     * 5. Load JSON configuration files
     * 6. Return configs and warnings
     *
     * @return StartupConfigResult Loaded configs and validation warnings
     *
     * @throws StartupConfigException When setup required, creation failed, loading failed, or validation failed
     */
    public function initialize(): StartupConfigResult
    {
        // Phase 1: Check for missing files
        $missingFiles = $this->findFilesToCreate($this->requiredFiles);

        if (!empty($missingFiles)) {
            $this->handleMissingFiles($missingFiles);
        }

        // Phase 2: Load env configuration
        $envConfig = $this->loadEnvConfig($this->envFiles);

        // Phase 3: Validate env configuration
        $validationResult = $this->validateEnvConfig($envConfig);

        // Phase 4: Load JSON configuration files
        $jsonConfigs = $this->loadJsonConfigs($this->jsonFiles);

        // Phase 5: Return result with warnings
        return new StartupConfigResult(
            envConfig: $envConfig,
            jsonConfigs: $jsonConfigs,
            warnings: $validationResult['warnings'] ?? []
        );
    }

    /**
     * Determine which required config files are missing and need creation.
     *
     * @param array<string> $requiredFiles Config filenames that must exist
     *
     * @return array<string> List of missing file names
     */
    private function findFilesToCreate(array $requiredFiles): array
    {
        try {
            return $this->creator->findFilesToCreate($requiredFiles, $this->configDir);
        } catch (Exception $e) {
            $this->logger->error('Failed to detect missing config files: ' . $e->getMessage());

            throw StartupConfigException::invalid(
                'Failed to check configuration files: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Attempt to create missing files from templates, then throw exception.
     *
     * This method ALWAYS throws a "configuration missing" exception: after
     * successful creation the user still needs to configure the new files,
     * and failed creation leaves them absent.
     *
     * @param array<string> $missingFiles List of missing file names
     *
     * @throws StartupConfigException Always
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

                throw StartupConfigException::missing(
                    'Failed to create required configuration files',
                    array_keys($failed),
                    ['failed_files' => array_keys($failed), 'example_dir' => $this->exampleDir],
                );
            }

            // All files created successfully - tell user to configure them
            $this->logger->info('All missing config files created successfully. Please review and update them with your settings.');

            throw StartupConfigException::missing(
                'Configuration files created from templates; user setup required',
                array_keys($created),
                ['created_files' => array_keys($created)],
            );
        } catch (StartupConfigException $e) {
            // Re-throw our own exceptions
            throw $e;
        } catch (Exception $e) {
            // Wrap other exceptions
            $this->logger->error('Unexpected error during config file creation: ' . $e->getMessage());

            throw StartupConfigException::missing(
                'Failed to create required configuration files',
                $missingFiles,
                ['failed_files' => $missingFiles, 'example_dir' => $this->exampleDir],
            );
        }
    }

    /**
     * Load configuration from environment files.
     *
     * @param array<string> $envFiles Dotenv files to load
     *
     * @return EnvConfig Loaded application configuration
     *
     * @throws StartupConfigException If loading fails
     */
    private function loadEnvConfig(array $envFiles): EnvConfig
    {
        try {
            return $this->envLoader->load($envFiles, $this->configDir);
        } catch (Exception $e) {
            $this->logger->critical('Configuration loading failed: ' . $e->getMessage());

            throw StartupConfigException::invalid(
                'Configuration loading failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Parse the JSON configuration files.
     *
     * @param array<string> $jsonFiles JSON config filenames to parse
     *
     * @return array<string, array<mixed>> Parsed configs keyed by filename
     *
     * @throws StartupConfigException If a file is missing, unreadable, or invalid
     */
    private function loadJsonConfigs(array $jsonFiles): array
    {
        $configs = [];

        foreach ($jsonFiles as $file) {
            $configs[$file] = $this->jsonLoader->loadArray($this->configDir . DIRECTORY_SEPARATOR . $file);
        }

        return $configs;
    }

    /**
     * Validate the loaded env configuration.
     *
     * @param EnvConfig $config Configuration to validate
     *
     * @return array<string, mixed> Validation result with 'is_valid', 'critical_errors', 'warnings'
     *
     * @throws StartupConfigException If critical validation errors exist
     */
    private function validateEnvConfig(EnvConfig $config): array
    {
        $validationResult = $this->envLoader->validateConfig($config);

        // Log critical errors
        if (!$validationResult['is_valid']) {
            foreach ($validationResult['critical_errors'] as $error) {
                $this->logger->critical("[{$error['identifier']}] {$error['message']}");
            }

            throw StartupConfigException::invalid(
                'Configuration validation failed with critical errors',
                ['validation_errors' => $validationResult['critical_errors']],
            );
        }

        // Log warnings (non-critical)
        foreach ($validationResult['warnings'] ?? [] as $warning) {
            $this->logger->warning("[{$warning['identifier']}] {$warning['message']}");
        }

        return $validationResult;
    }
}
