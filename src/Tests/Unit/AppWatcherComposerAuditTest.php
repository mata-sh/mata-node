<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Services\Command\CommandResult;
use Mata\Tests\Support\FakeProcessCommandRunner;
use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Watchers\AppWatcher
 *
 * @internal
 */
final class AppWatcherComposerAuditTest extends TestCase
{
    public function testComposerAuditReturnsTimeoutError(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/composer.json', '{}');
        file_put_contents($rootPath . '/app/composer.lock', '{}');

        $processRunner = new FakeProcessCommandRunner(new CommandResult('', 'late', 124, true, false, 60_000));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                ['app' => ['path' => 'app', 'composer' => true]],
                [],
                null,
                null,
                null,
                $processRunner,
            );

            $result = $watcher->getAppsComposerAudit();

            $this->assertSame('error', $result['app']['status']);
            $this->assertSame(124, $result['app']['exit_code']);
            $this->assertSame('composer audit timed out', $result['app']['message']);
            $this->assertSame(60, $processRunner->calls[0]['timeoutSeconds']);
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testComposerAuditReturnsOutputLimitError(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/composer.json', '{}');
        file_put_contents($rootPath . '/app/composer.lock', '{}');

        $processRunner = new FakeProcessCommandRunner(new CommandResult('{"partial":', '', 125, false, true, 10));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                ['app' => ['path' => 'app', 'composer' => true]],
                [],
                null,
                null,
                null,
                $processRunner,
            );

            $result = $watcher->getAppsComposerAudit();

            $this->assertSame('error', $result['app']['status']);
            $this->assertSame(125, $result['app']['exit_code']);
            $this->assertSame('composer audit output exceeded maximum size', $result['app']['message']);
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
