<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Utils\PathResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Utils\PathResolver
 *
 * @internal
 */
class PathResolverTest extends TestCase
{
    public function testDefaultsCachePathToTempCache(): void
    {
        $rootPath = $this->createAppRoot();

        try {
            $paths = PathResolver::fromEntryPoint($rootPath . '/public/index.php');

            $this->assertSame($rootPath . '/temp', $paths->tempPath);
            $this->assertSame($rootPath . '/temp/cache', $paths->cachePath);
        } finally {
            $this->removeDirectory(dirname($rootPath));
        }
    }

    public function testOverridesResolveAgainstAppPathAndKeepCacheUnderTemp(): void
    {
        $rootPath = $this->createAppRoot();

        try {
            $defaults = PathResolver::fromEntryPoint($rootPath . '/public/index.php');
            $paths = $defaults->withOverrides('runtime-logs/node.log', 'runtime-temp');
            $paths->ensureDirectories();

            $this->assertSame($rootPath . '/runtime-logs/node.log', $paths->appLogPath);
            $this->assertSame($rootPath . '/runtime-logs', $paths->logsPath);
            $this->assertSame($rootPath . '/runtime-temp', $paths->tempPath);
            $this->assertSame($rootPath . '/runtime-temp/cache', $paths->cachePath);
            $this->assertSame($defaults->bootLogPath, $paths->bootLogPath);
            $this->assertDirectoryExists($rootPath . '/runtime-logs');
            $this->assertDirectoryExists($rootPath . '/runtime-temp/cache');
        } finally {
            $this->removeDirectory(dirname($rootPath));
        }
    }

    public function testBootLogFallsBackToSystemTempWithoutLogsDirectory(): void
    {
        $rootPath = $this->createAppRoot();
        $previousEnv = $_ENV['APP_LOG_PATH'] ?? null;
        $previousProcess = getenv('APP_LOG_PATH');
        unset($_ENV['APP_LOG_PATH']);
        putenv('APP_LOG_PATH');

        try {
            $paths = PathResolver::fromEntryPoint($rootPath . '/public/index.php');

            $this->assertSame(sys_get_temp_dir() . '/mata-boot.log', $paths->bootLogPath);
        } finally {
            if ($previousEnv !== null) {
                $_ENV['APP_LOG_PATH'] = $previousEnv;
            }
            putenv($previousProcess === false ? 'APP_LOG_PATH' : 'APP_LOG_PATH=' . $previousProcess);
            $this->removeDirectory(dirname($rootPath));
        }
    }

    private function createAppRoot(): string
    {
        $basePath = sys_get_temp_dir() . '/mata-node-path-resolver-' . bin2hex(random_bytes(6));
        $rootPath = $basePath . '/app';
        mkdir($rootPath . '/public', 0777, true);
        file_put_contents($rootPath . '/public/index.php', '<?php');

        return $rootPath;
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

            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
