<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\SessionBackends;

use Mata\Watchers\SessionBackends\FileSessionBackend;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\SessionBackends\FileSessionBackend
 *
 * @internal
 */
class FileSessionBackendTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/mata-node-file-sessions-' . bin2hex(random_bytes(6));
        mkdir($this->rootPath . '/sessions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootPath);
    }

    public function testCountsFileSessionsWithRegexAndSize(): void
    {
        file_put_contents($this->rootPath . '/sessions/sess_recent', 'recent');
        file_put_contents($this->rootPath . '/sessions/sess_old', 'old');
        file_put_contents($this->rootPath . '/sessions/ignored', 'ignored');
        touch($this->rootPath . '/sessions/sess_recent', time() - 60);
        touch($this->rootPath . '/sessions/sess_old', time() - 7200);

        $metadata = (new FileSessionBackend())->getMetadata([
            'path' => 'sessions',
            'filename_regex' => '/^sess_/',
        ], $this->rootPath);

        $this->assertSame([
            'type' => 'file',
            'status' => 'ok',
            'count_last_hour' => 1,
            'count_last_day' => 2,
            'count_total' => 2,
            'path' => 'sessions',
            'size_kb' => round((strlen('recent') + strlen('old')) / 1024, 2),
        ], $metadata);
    }

    public function testCountsFileSessionsAtAbsolutePath(): void
    {
        file_put_contents($this->rootPath . '/sessions/sess_recent', 'recent');
        $absolutePath = $this->rootPath . '/sessions';

        $metadata = (new FileSessionBackend())->getMetadata([
            'path' => $absolutePath,
        ], '/nonexistent-root');

        $this->assertSame('ok', $metadata['status']);
        $this->assertSame(1, $metadata['count_total']);
        $this->assertSame($absolutePath, $metadata['path']);
    }

    public function testMissingSessionDirectoryReturnsZeroCounts(): void
    {
        $metadata = (new FileSessionBackend())->getMetadata([
            'path' => 'missing',
        ], $this->rootPath);

        $this->assertSame('file', $metadata['type']);
        $this->assertSame('ok', $metadata['status']);
        $this->assertSame(0, $metadata['count_last_hour']);
        $this->assertSame(0, $metadata['count_last_day']);
        $this->assertSame(0, $metadata['count_total']);
        $this->assertSame('missing', $metadata['path']);
        $this->assertSame(0.0, $metadata['size_kb']);
    }

    public function testInvalidFilenameRegexReturnsErrorStatus(): void
    {
        file_put_contents($this->rootPath . '/sessions/sess_recent', 'recent');

        $metadata = (new FileSessionBackend())->getMetadata([
            'path' => 'sessions',
            'filename_regex' => '/^sess_(/',
        ], $this->rootPath);

        $this->assertSame('file', $metadata['type']);
        $this->assertSame('error', $metadata['status']);
        $this->assertStringStartsWith('Invalid filename_regex:', $metadata['error']);
        $this->assertNull($metadata['count_last_hour']);
        $this->assertNull($metadata['count_last_day']);
        $this->assertNull($metadata['count_total']);
        $this->assertSame('sessions', $metadata['path']);
        $this->assertNull($metadata['size_kb']);
    }

    public function testInvalidFilenameRegexReturnsErrorEvenWithoutPath(): void
    {
        $metadata = (new FileSessionBackend())->getMetadata([
            'filename_regex' => '/^sess_(/',
        ], $this->rootPath);

        $this->assertSame('file', $metadata['type']);
        $this->assertSame('error', $metadata['status']);
        $this->assertStringStartsWith('Invalid filename_regex:', $metadata['error']);
        $this->assertNull($metadata['count_last_hour']);
        $this->assertNull($metadata['count_last_day']);
        $this->assertNull($metadata['count_total']);
        $this->assertNull($metadata['path']);
        $this->assertNull($metadata['size_kb']);
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
