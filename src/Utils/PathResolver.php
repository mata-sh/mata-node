<?php

declare(strict_types=1);

namespace Mata\Utils;

use RuntimeException;

class PathResolver
{
    public string $appPath;
    public string $rootPath;
    public string $logsPath;
    public string $tempPath;
    public string $cachePath;

    public function __construct(string $entryPointFile)
    {
        $this->appPath = dirname(realpath($entryPointFile), 2);
        $this->rootPath = dirname($this->appPath);
        $this->logsPath = $this->appPath . DIRECTORY_SEPARATOR . 'logs';
        $this->tempPath = $this->appPath . DIRECTORY_SEPARATOR . 'temp';
        $this->cachePath = $this->tempPath . DIRECTORY_SEPARATOR . 'cach';

        // Ensure essential paths exist and are writable
        $this->ensureDirectory($this->logsPath);
        $this->ensureDirectory($this->tempPath);
        $this->ensureDirectory($this->cachePath);
    }

    /**
     * Ensure a directory exists and is writable; create it if not existing.
     *
     * @throws RuntimeException if the directory is not writable or cannot be created
     */
    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0775, true)) {
                throw new RuntimeException("Failed to create directory: {$path}");
            }
        }

        if (!is_writable($path)) {
            throw new RuntimeException("Directory is not writable: {$path}");
        }
    }
}
