<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

final class FileSessionBackend implements SessionBackendInterface
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function getMetadata(array $config, string $rootPath): array
    {
        $sessionPath = is_string($config['path'] ?? null) ? $config['path'] : '';
        $metadata = $this->baseMetadata($sessionPath !== '' ? $sessionPath : null);

        if ($sessionPath === '') {
            return $metadata;
        }

        $sessionsDirPath = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sessionPath;
        if (!is_dir($sessionsDirPath)) {
            return $metadata;
        }

        $allFiles = scandir($sessionsDirPath);
        if ($allFiles === false) {
            return $metadata;
        }

        $now = time();
        $sizeBytes = 0;
        $filenameRegex = is_string($config['filename_regex'] ?? null) ? $config['filename_regex'] : '';

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

        return @preg_match($filenameRegex, $filename) === 1;
    }
}
