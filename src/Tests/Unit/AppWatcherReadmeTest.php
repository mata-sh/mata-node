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
    public function testGetAppReadmeReturnsContentObject(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-readme-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/README.md', "# Test\n\nREADME");

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                ['app' => ['path' => 'app']],
                [],
            );

            $this->assertSame(
                ['content' => "# Test\n\nREADME"],
                $watcher->getAppReadme('app')
            );
        } finally {
            $this->removeDirectory($rootPath);
        }
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
