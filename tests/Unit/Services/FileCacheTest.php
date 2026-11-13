<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\FileCache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\FileCache
 *
 * @internal
 */
class FileCacheTest extends TestCase
{
    public function testSetThenGetFreshHitRoundTripsArraysAndInts(): void
    {
        $cachePath = $this->tempPath('mata-node-file-cache-');

        try {
            $cache = new FileCache($cachePath);

            $this->assertTrue($cache->set('array-key', ['count' => 3, 'items' => ['a', 'b']]));
            $this->assertSame(['count' => 3, 'items' => ['a', 'b']], $cache->get('array-key', 60));

            $this->assertTrue($cache->set('int-key', 42));
            $this->assertSame(42, $cache->get('int-key', 60));
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testStaleEntryReturnsNull(): void
    {
        $cachePath = $this->tempPath('mata-node-file-cache-');

        try {
            $cache = new FileCache($cachePath);
            $cache->set('stale', ['value' => true]);
            touch($cachePath . '/stale.json', time() - 3600);

            $this->assertNull($cache->get('stale', 10));
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testDisabledTtlReturnsNullEvenWhenFresh(): void
    {
        $cachePath = $this->tempPath('mata-node-file-cache-');

        try {
            $cache = new FileCache($cachePath);
            $cache->set('fresh', ['value' => true]);

            $this->assertNull($cache->get('fresh', 0));
            $this->assertNull($cache->get('fresh', -1));
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testCorruptJsonReturnsNull(): void
    {
        $cachePath = $this->tempPath('mata-node-file-cache-');
        mkdir($cachePath, 0777, true);

        try {
            file_put_contents($cachePath . '/corrupt.json', '{broken');

            $this->assertNull((new FileCache($cachePath))->get('corrupt', 60));
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testRememberCallsProducerOnceAcrossFreshCalls(): void
    {
        $cachePath = $this->tempPath('mata-node-file-cache-');

        try {
            $cache = new FileCache($cachePath);
            $calls = 0;
            $producer = static function () use (&$calls): array {
                ++$calls;

                return ['calls' => $calls];
            };

            $this->assertSame(['calls' => 1], $cache->remember('remembered', 60, $producer));
            $this->assertSame(['calls' => 1], $cache->remember('remembered', 60, $producer));
            $this->assertSame(1, $calls);
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testRememberWithDisabledTtlCallsProducerEveryTimeAndWritesNothing(): void
    {
        $cachePath = $this->tempPath('mata-node-file-cache-');

        try {
            $cache = new FileCache($cachePath);
            $calls = 0;
            $producer = static function () use (&$calls): int {
                ++$calls;

                return $calls;
            };

            $this->assertSame(1, $cache->remember('disabled', 0, $producer));
            $this->assertSame(2, $cache->remember('disabled', 0, $producer));
            $this->assertSame(2, $calls);
            $this->assertDirectoryDoesNotExist($cachePath);
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testEscapeAttemptNeverCreatesFilesOutsideCacheDir(): void
    {
        $parentPath = $this->tempPath('mata-node-file-cache-parent-');
        $cachePath = $parentPath . '/cache';

        try {
            $cache = new FileCache($cachePath);

            $this->assertTrue($cache->set('../escape/attempt', ['safe' => true]));
            $this->assertFileDoesNotExist($parentPath . '/escape/attempt.json');
            $this->assertFileExists($cachePath . '/escape/attempt.json');
        } finally {
            $this->removeDirectory($parentPath);
        }
    }

    public function testSetCreatesNestedDirectories(): void
    {
        $cachePath = $this->tempPath('mata-node-file-cache-');

        try {
            $cache = new FileCache($cachePath);

            $this->assertTrue($cache->set('nested/deep/value', 123));
            $this->assertFileExists($cachePath . '/nested/deep/value.json');
            $this->assertSame(123, $cache->get('nested/deep/value', 60));
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    private function tempPath(string $prefix): string
    {
        return sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
