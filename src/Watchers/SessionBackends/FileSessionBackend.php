<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

use Mata\Utils\Tools;

final class FileSessionBackend implements SessionBackendInterface
{
    public function __construct(private readonly Tools $tools = new Tools()) {}

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function getMetadata(array $config, string $rootPath, ?string $cacheIdentity = null): array
    {
        $sessionPath = is_string($config['path'] ?? null) ? $config['path'] : '';
        $metadata = $this->baseMetadata($sessionPath !== '' ? $sessionPath : null);

        $filenameRegex = is_string($config['filename_regex'] ?? null) ? $config['filename_regex'] : '';
        $regexError = $this->validateFilenameRegex($filenameRegex);
        if ($regexError !== null) {
            $metadata['status'] = 'error';
            $metadata['error'] = sprintf('Invalid filename_regex: %s', $regexError);
            $metadata['count_last_hour'] = null;
            $metadata['count_last_day'] = null;
            $metadata['count_total'] = null;
            $metadata['size_kb'] = null;

            return $metadata;
        }

        if ($sessionPath === '') {
            return $metadata;
        }

        $sessionsDirPath = $this->tools->normalizePath($sessionPath, $rootPath);
        if (!is_dir($sessionsDirPath)) {
            return $metadata;
        }

        $allFiles = scandir($sessionsDirPath);
        if ($allFiles === false) {
            return $metadata;
        }

        $now = time();
        $sizeBytes = 0;

        foreach ($allFiles as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            if (!$this->matchesFilenameRegex($filename, $filenameRegex)) {
                continue;
            }

            $file = $sessionsDirPath . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($file)) {
                continue;
            }

            ++$metadata['count_total'];
            $fileSize = filesize($file);
            if ($fileSize !== false) {
                $sizeBytes += $fileSize;
            }

            $mtime = filemtime($file);
            if ($mtime === false) {
                continue;
            }

            $age = $now - $mtime;
            if ($age >= 0 && $age <= 3600) {
                ++$metadata['count_last_hour'];
            }
            if ($age >= 0 && $age <= 86400) {
                ++$metadata['count_last_day'];
            }
        }

        $metadata['size_kb'] = round($sizeBytes / 1024, 2);

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMetadata(?string $path): array
    {
        return [
            'type' => 'file',
            'status' => 'ok',
            'count_last_hour' => 0,
            'count_last_day' => 0,
            'count_total' => 0,
            'path' => $path,
            'size_kb' => 0.0,
        ];
    }

    private function matchesFilenameRegex(string $filename, string $filenameRegex): bool
    {
        if ($filenameRegex === '') {
            return true;
        }

        return preg_match($filenameRegex, $filename) === 1;
    }

    private function validateFilenameRegex(string $filenameRegex): ?string
    {
        if ($filenameRegex === '') {
            return null;
        }

        // preg_match() reports a broken pattern as an E_WARNING, not an
        // exception; capture its text for the error message.
        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $isValid = preg_match($filenameRegex, '') !== false;
        } finally {
            restore_error_handler();
        }

        return $isValid ? null : ($error ?? 'invalid pattern');
    }
}
