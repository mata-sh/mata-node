<?php

declare(strict_types=1);

namespace Mata\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Handles creation and verification of environment configuration files
 * from example templates.
 */
class EnvConfigCreator
{
    /**
     * Mapping of required config files to their template equivalents.
     */
    private const TEMPLATE_MAP = [
        'config.env' => 'config.env.example',
        'db.config.env' => 'db.config.example',
        'mail.config.env' => 'mail.config.env.example',
    ];

    /**
     * Check which required config files are missing.
     *
     * @param array<string> $requiredFiles List of required config file names
     * @param string        $configDir     Configuration directory path
     *
     * @return array<string> List of missing file names
     */
    public function getMissingFiles(array $requiredFiles, string $configDir): array
    {
        if (!is_dir($configDir)) {
            throw new InvalidArgumentException("Config directory does not exist: {$configDir}");
        }

        return array_filter(
            $requiredFiles,
            function ($file) use ($configDir): bool {
                $path = $configDir . '/' . $file;

                if (file_exists($path) && !is_readable($path)) {
                    throw new RuntimeException("Config file exists but is not readable: {$path}");
                }

                return !file_exists($path);
            }
        );
    }

    /**
     * Create missing config files from example templates.
     *
     * @param array<string> $missingFiles List of missing config file names
     * @param string        $configDir    Target configuration directory path
     * @param string        $exampleDir   Source template directory path (default: config/example)
     *
     * @return array<string, bool> Map of filename => success status
     *
     * @throws RuntimeException If template directory doesn't exist
     */
    public function createFromTemplates(
        array $missingFiles,
        string $configDir,
        ?string $exampleDir = null
    ): array {
        if ($exampleDir === null) {
            $exampleDir = dirname($configDir) . '/config/example';
        }

        if (!is_dir($exampleDir)) {
            throw new RuntimeException("Template directory does not exist: {$exampleDir}");
        }

        $results = [];

        foreach ($missingFiles as $file) {
            $results[$file] = $this->createConfigFile($file, $configDir, $exampleDir);
        }

        return $results;
    }

    /**
     * Create a single config file from its template.
     *
     * @param string $filename   Config file name to create
     * @param string $configDir  Target directory
     * @param string $exampleDir Template directory
     *
     * @return bool True if created successfully, false otherwise
     */
    private function createConfigFile(string $filename, string $configDir, string $exampleDir): bool
    {
        $templateName = self::TEMPLATE_MAP[$filename] ?? null;

        if ($templateName === null) {
            // No template mapping found, try adding .example
            $templateName = $filename . '.example';
        }

        $templatePath = $exampleDir . '/' . $templateName;
        $targetPath = $configDir . '/' . $filename;

        if (file_exists($targetPath)) {
            return false;
        }

        if (!is_readable($templatePath)) {
            return false;
        }

        // Read template content
        $content = file_get_contents($templatePath);
        if ($content === false) {
            return false;
        }

        // Write to target with restrictive permissions for security
        $result = file_put_contents($targetPath, $content);
        if ($result === false) {
            return false;
        }

        // Set restrictive permissions (owner read/write only)
        chmod($targetPath, 0600);

        return true;
    }

    /**
     * Check if all required config files exist and are readable.
     *
     * @param array<string> $requiredFiles List of required config file names
     * @param string        $configDir     Configuration directory path
     *
     * @return bool True if all files exist and are readable
     */
    public function allConfigsExist(array $requiredFiles, string $configDir): bool
    {
        return empty($this->getMissingFiles($requiredFiles, $configDir));
    }

    /**
     * Get information about missing config files and their templates.
     *
     * @param array<string> $missingFiles List of missing config file names
     * @param string        $exampleDir   Template directory path
     *
     * @return array<string, array{file: string, template: string, template_exists: bool}>
     */
    public function getMissingFilesInfo(array $missingFiles, string $exampleDir): array
    {
        $info = [];

        foreach ($missingFiles as $file) {
            $templateName = self::TEMPLATE_MAP[$file] ?? $file . '.example';
            $templatePath = $exampleDir . '/' . $templateName;

            $info[$file] = [
                'file' => $file,
                'template' => $templateName,
                'template_exists' => is_readable($templatePath),
            ];
        }

        return $info;
    }
}
