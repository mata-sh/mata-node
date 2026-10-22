<?php

declare(strict_types=1);

namespace Mata\Config;

use RuntimeException;

/**
 * Handles creation and verification of configuration files
 * from example templates (named `<filename>.example`).
 */
class EnvConfigCreator
{
    /**
     * Check which required config files need to be created.
     *
     * A missing config directory means all required files need creation.
     *
     * @param array<string> $requiredFiles List of required config file names
     * @param string        $configDir     Configuration directory path
     *
     * @return array<string> List of missing file names
     */
    public function findFilesToCreate(array $requiredFiles, string $configDir): array
    {
        if (!is_dir($configDir)) {
            return $requiredFiles;
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
        $templatePath = $exampleDir . '/' . $filename . '.example';
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
}
