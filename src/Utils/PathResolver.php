<?php

declare(strict_types=1);

namespace Mata\Utils;

use InvalidArgumentException;
use Mata\Config\Dto\EnvConfig;
use RuntimeException;

class PathResolver
{
    public string $appPath;
    public string $rootPath;
    public string $logsPath;
    public string $appLogPath;
    public string $bootLogPath;
    public string $tempPath;
    public string $cachePath;

    public function __construct(string $entryPointFile)
    {
        $realPath = realpath($entryPointFile);

        if ($realPath === false) {
            throw new InvalidArgumentException('Entry point file not found: ' . $entryPointFile);
        }

        $this->appPath = dirname($realPath, 2);
        $rootPath = dirname($this->appPath);

        if ($rootPath === '.' || $rootPath === '') {
            throw new RuntimeException('Cannot resolve root path from: ' . $this->appPath);
        }

        $this->rootPath = $rootPath;
        $this->logsPath = $this->appPath . DIRECTORY_SEPARATOR . 'logs';
        $this->appLogPath = $this->logsPath . DIRECTORY_SEPARATOR . 'app.log';
        $this->bootLogPath = $this->resolveBootLogPath();
        $this->tempPath = $this->appPath . DIRECTORY_SEPARATOR . 'temp';
        $this->cachePath = $this->tempPath . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * Apply config overrides for log/temp paths and ensure the resulting
     * directories exist and are writable (created if absent).
     */
    public function applyConfiguredPaths(EnvConfig $config): void
    {
        $logPath = trim($config->logging->path);
        if ($logPath !== '') {
            $this->appLogPath = $this->resolvePath($logPath);
            $this->logsPath = dirname($this->appLogPath);
        }

        $tempDir = trim($config->environment->tempDir);
        if ($tempDir !== '') {
            $this->tempPath = $this->resolvePath($tempDir);
        }

        $this->cachePath = $this->tempPath . DIRECTORY_SEPARATOR . 'cache';

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

    /**
     * Resolve a path, treating relative paths as app-root relative.
     */
    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $this->appPath;
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->appPath . DIRECTORY_SEPARATOR . $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix-style absolute path
        if ($path[0] === DIRECTORY_SEPARATOR) {
            return true;
        }

        // Windows drive letter paths like C:\ or C:/
        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private function resolveBootLogPath(): string
    {
        $envAppLogPath = $this->getSystemEnv('APP_LOG_PATH');
        if ($envAppLogPath !== null) {
            $candidate = $this->resolvePath($envAppLogPath);
            $dir = dirname($candidate);
            if (is_dir($dir) && is_writable($dir)) {
                return $candidate;
            }
        }

        $bootPath = $this->logsPath . DIRECTORY_SEPARATOR . 'boot.log';
        $bootDir = dirname($bootPath);

        if (is_dir($bootDir) && is_writable($bootDir)) {
            return $bootPath;
        }

        return sys_get_temp_dir() . 'mata-boot.log';
    }

    private function getSystemEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
