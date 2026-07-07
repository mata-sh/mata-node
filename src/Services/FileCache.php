<?php

declare(strict_types=1);

namespace Mata\Services;

use Throwable;

final class FileCache
{
    public function __construct(
        private string $cacheDir
    ) {}

    /**
     * Returns null on miss, stale entries, corrupt JSON, disabled TTL, or when
     * a stored value decodes to null. Storing null is indistinguishable from a miss.
     */
    public function get(string $key, int $ttlSeconds): mixed
    {
        if ($ttlSeconds <= 0) {
            return null;
        }

        try {
            $cacheFile = $this->cacheFile($key);
            if ($cacheFile === null || !is_file($cacheFile)) {
                return null;
            }

            $cacheMtime = @filemtime($cacheFile);
            if ($cacheMtime === false || time() - $cacheMtime > $ttlSeconds) {
                return null;
            }

            $contents = @file_get_contents($cacheFile);
            if ($contents === false) {
                return null;
            }

            return json_decode($contents, true);
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $key, mixed $data): bool
    {
        try {
            $cacheFile = $this->cacheFile($key);
            if ($cacheFile === null) {
                return false;
            }

            $encoded = json_encode($data);
            if ($encoded === false) {
                return false;
            }

            $cacheDir = dirname($cacheFile);
            if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
                return false;
            }

            $tempFile = @tempnam($cacheDir, '.filecache-');
            if ($tempFile === false) {
                return false;
            }

            if (@file_put_contents($tempFile, $encoded, LOCK_EX) === false) {
                @unlink($tempFile);

                return false;
            }

            if (!@rename($tempFile, $cacheFile)) {
                @unlink($tempFile);

                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $cached = $this->get($key, $ttlSeconds);
        if ($cached !== null) {
            return $cached;
        }

        $data = $producer();
        if ($ttlSeconds > 0) {
            $this->set($key, $data);
        }

        return $data;
    }

    private function cacheFile(string $key): ?string
    {
        if ($this->cacheDir === '') {
            return null;
        }

        $segments = [];
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }

            $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $segment);
            if ($sanitized === null) {
                return null;
            }

            $segments[] = $sanitized;
        }

        if ($segments === []) {
            return null;
        }

        return rtrim($this->cacheDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, $segments)
            . '.json';
    }
}
