<?php

declare(strict_types=1);

namespace Mata\Security\NonceStore;

use RuntimeException;

final class FileNonceStore implements NonceStoreInterface
{
    private const FILE_EXTENSION = '.json';
    private const GC_DIVISOR = 100;

    public function __construct(private readonly string $path)
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new RuntimeException('Failed to create nonce store directory: ' . $this->path);
        }

        if (!is_writable($this->path)) {
            throw new RuntimeException('Nonce store directory is not writable: ' . $this->path);
        }
    }

    public function consume(string $nonce, int $expiresAt): bool
    {
        if (mt_rand(1, self::GC_DIVISOR) === 1) {
            $this->cleanup();
        }

        $file = $this->fileForNonce($nonce);
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open nonce store file: ' . $file);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Failed to lock nonce store file: ' . $file);
            }

            clearstatcache(true, $file);
            $mtime = filemtime($file);

            if ($mtime !== false && $mtime > time()) {
                return false;
            }

            if (!touch($file, $expiresAt)) {
                throw new RuntimeException('Failed to update nonce store file: ' . $file);
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function count(): int
    {
        $this->cleanup();

        return count($this->files());
    }

    public function clear(): void
    {
        foreach ($this->files() as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function cleanup(): void
    {
        $now = time();

        foreach ($this->files() as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime <= $now) {
                @unlink($file);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function files(): array
    {
        $files = glob($this->path . DIRECTORY_SEPARATOR . '*' . self::FILE_EXTENSION);

        return $files === false ? [] : $files;
    }

    private function fileForNonce(string $nonce): string
    {
        return $this->path . DIRECTORY_SEPARATOR . hash('sha256', $nonce) . self::FILE_EXTENSION;
    }
}
