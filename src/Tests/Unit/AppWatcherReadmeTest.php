<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Watchers\AppWatcher
 *
 * @internal
 */
final class AppWatcherReadmeTest extends TestCase
{
    public function testGetAppReadmeUsesDefaultPath(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-readme-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/README.md', "# Test\n\nREADME");

        try {
            $this->assertSame(
                ['content' => "# Test\n\nREADME"],
                $this->createWatcher($rootPath, ['path' => 'app'])->getAppReadme('app')
            );
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testGetAppReadmeReturnsNotFoundPayloadWhenReadmeIsMissing(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-readme-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);

        try {
            $this->assertSame(
                [
                    'content' => '',
                    'notice' => [
                        'code' => 'README_NOT_FOUND',
                        'message' => 'README not found',
                    ],
                ],
                $this->createWatcher($rootPath, ['path' => 'app'])->getAppReadme('app')
            );
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testGetAppReadmeUsesConfiguredRelativePath(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-readme-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/documentation', 0777, true);
        file_put_contents($rootPath . '/documentation/application.md', '# Custom README');

        try {
            $this->assertSame(
                ['content' => '# Custom README'],
                $this->createWatcher($rootPath, [
                    'path' => 'app',
                    'readme' => 'documentation/application.md',
                ])->getAppReadme('app')
            );
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testGetAppReadmeUsesConfiguredAbsolutePath(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-readme-' . bin2hex(random_bytes(6));
        $readmePath = $rootPath . '-external.md';
        mkdir($rootPath, 0777, true);
        file_put_contents($readmePath, '# External README');

        try {
            $this->assertSame(
                ['content' => '# External README'],
                $this->createWatcher($rootPath, [
                    'path' => 'app',
                    'readme' => $readmePath,
                ])->getAppReadme('app')
            );
        } finally {
            $this->removeDirectory($rootPath);
            unlink($readmePath);
        }
    }

    /**
     * @param array<string, string> $appConfig
     */
    private function createWatcher(string $rootPath, array $appConfig): AppWatcher
    {
        return new AppWatcher(
            new Tools(),
            new NullLogger(),
            $rootPath,
            ['app' => $appConfig],
            [],
        );
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
