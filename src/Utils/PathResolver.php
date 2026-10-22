<?php

declare(strict_types=1);

namespace Mata\Utils;

use InvalidArgumentException;
use RuntimeException;

class PathResolver
{
    public readonly string $appPath;
    public readonly string $rootPath;
    public readonly string $logsPath;
    public readonly string $appLogPath;
    public readonly string $bootLogPath;
    public readonly string $tempPath;
    public readonly string $cachePath;

    public static function fromEntryPoint(string $entryPointFile): self
    {
        $realPath = realpath($entryPointFile);
        if ($realPath === false) {
            throw new InvalidArgumentException('Entry point file not found: ' . $entryPointFile);
        }

        return new self(dirname($realPath, 2));
    }

    private function __construct(string $appPath, string $logPath = '', string $tempDir = '', ?string $bootLogPath = null)
    {
        $rootPath = dirname($appPath);
        if ($rootPath === '.' || $rootPath === '') {
            throw new RuntimeException('Cannot resolve root path from: ' . $appPath);
        }

        $this->appPath = $appPath;
        $this->rootPath = $rootPath;
        $this->appLogPath = $logPath !== '' ? $this->resolvePath($logPath) : $appPath . '/logs/app.log';
        $this->logsPath = dirname($this->appLogPath);
        $this->bootLogPath = $bootLogPath ?? $this->resolveBootLogPath();
        $this->tempPath = $tempDir !== '' ? $this->resolvePath($tempDir) : $appPath . '/temp';
        $this->cachePath = $this->tempPath . '/cache';
    }

    /**
     * Apply APP_LOG_PATH and TEMP_DIR. Relative values resolve against the app path, empty ones
     * keep the defaults; the boot log path stays, so boot errors of one request go to one file.
     */
    public function withOverrides(string $logPath, string $tempDir): self
    {
        return new self($this->appPath, trim($logPath), trim($tempDir), $this->bootLogPath);
    }

    /**
     * Create the logs, temp and cache directories if missing.
     *
     * @throws RuntimeException if a directory cannot be created or is not writable
     */
    public function ensureDirectories(): void
    {
        foreach ([$this->logsPath, $this->tempPath, $this->cachePath] as $path) {
            // Concurrent requests may create the directory between the check and mkdir().
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException("Failed to create directory: {$path}");
            }

            if (!is_writable($path)) {
                throw new RuntimeException("Directory is not writable: {$path}");
            }
        }
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->appPath . '/' . $path;
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

        if (is_dir($this->logsPath) && is_writable($this->logsPath)) {
            return $this->logsPath . '/boot.log';
        }

        return sys_get_temp_dir() . '/mata-boot.log';
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
