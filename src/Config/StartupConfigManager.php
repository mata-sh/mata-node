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
     * Create missing files from templates, then load and validate the env files and parse the JSON files.
     *
     * @throws StartupConfigException When setup is required, creation failed, loading failed, or validation failed
     */
    public function initialize(): StartupConfigResult
    {
        $missingFiles = $this->findFilesToCreate($this->requiredFiles);
        if ($missingFiles !== []) {
            $this->handleMissingFiles($missingFiles);
        }

        $envConfig = $this->loadEnvConfig($this->envFiles);
        $this->validateEnvConfig($envConfig);

        return new StartupConfigResult(
            envConfig: $envConfig,
            jsonConfigs: $this->loadJsonConfigs($this->jsonFiles),
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
        foreach ($missingFiles as $file) {
            $this->logger->critical("Missing config file: {$this->configDir}/{$file}");
        }

        $this->logger->info('Attempting to create missing config files from templates...');

        try {
            $results = $this->creator->createFromTemplates($missingFiles, $this->configDir, $this->exampleDir);
        } catch (Exception $e) {
            $this->logger->error('Unexpected error during config file creation: ' . $e->getMessage());
            $missingFiles = array_values($missingFiles);

            throw StartupConfigException::missing(
                'Failed to create required configuration files',
                $missingFiles,
                ['failed_files' => $missingFiles, 'example_dir' => $this->exampleDir],
            );
        }

        $created = array_keys(array_filter($results));
        $failed = array_keys(array_filter($results, static fn (bool $success): bool => !$success));

        if ($created !== []) {
            $this->logger->info('Created config files from templates: ' . implode(', ', $created));
        }

        if ($failed !== []) {
            $this->logger->error('Failed to create config files: ' . implode(', ', $failed));

            throw StartupConfigException::missing(
                'Failed to create required configuration files',
                $failed,
                ['failed_files' => $failed, 'example_dir' => $this->exampleDir],
            );
        }

        $this->logger->info('All missing config files created successfully. Please review and update them with your settings.');

        throw StartupConfigException::missing(
            'Configuration files created from templates; user setup required',
            $created,
            ['created_files' => $created],
        );
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
     * @return array<string, array<string, mixed>> Parsed configs keyed by filename
     *
     * @throws StartupConfigException If a file is missing, unreadable, or invalid
     */
    private function loadJsonConfigs(array $jsonFiles): array
    {
        $configs = [];

        foreach ($jsonFiles as $file) {
            $configs[$file] = $this->jsonLoader->loadArray($this->configDir . '/' . $file);
        }

        return $configs;
    }

    /**
     * @throws StartupConfigException If critical validation errors exist
     */
    private function validateEnvConfig(EnvConfig $config): void
    {
        $errors = $this->envLoader->validateConfig($config);
        if ($errors === []) {
            return;
        }

        foreach ($errors as $error) {
            $this->logger->critical("[{$error['identifier']}] {$error['message']}");
        }

        throw StartupConfigException::invalid(
            'Configuration validation failed with critical errors',
            ['validation_errors' => $errors],
        );
    }
}
