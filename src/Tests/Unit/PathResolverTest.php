<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\AuthConfig;
use Mata\Config\Dto\ComposerConfig;
use Mata\Config\Dto\EnvConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
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
            $paths = new PathResolver($rootPath . '/public/index.php');

            $this->assertSame($rootPath . '/temp', $paths->tempPath);
            $this->assertSame($rootPath . '/temp/cache', $paths->cachePath);
        } finally {
            $this->removeDirectory(dirname($rootPath));
        }
    }

    public function testTempDirOverrideKeepsCacheUnderTemp(): void
    {
        $rootPath = $this->createAppRoot();

        try {
            $paths = new PathResolver($rootPath . '/public/index.php');
            $paths->applyConfiguredPaths($this->createConfig(tempDir: 'runtime-temp'));

            $this->assertSame($rootPath . '/runtime-temp', $paths->tempPath);
            $this->assertSame($rootPath . '/runtime-temp/cache', $paths->cachePath);
            $this->assertDirectoryExists($rootPath . '/runtime-temp/cache');
        } finally {
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

    private function createConfig(string $tempDir = ''): EnvConfig
    {
        return new EnvConfig(
            environment: new EnvironmentConfig(
                env: 'test',
                domain: 'https://example.test',
                dir: '',
                tempDir: $tempDir,
            ),
            general: new GeneralConfig(
                maintenanceModeEnabled: false,
                ipWhitelistEnabled: false,
                allowedIps: [],
                trustedProxies: [],
            ),
            php: new PhpConfig(
                displayErrors: 0,
                logLevel: 'notices',
            ),
            logging: new LoggingConfig(
                path: 'logs/app.log',
                level: 'debug',
            ),
            rateLimiting: new RateLimitingConfig(
                enabled: true,
                storageType: 'file',
                filePath: null,
                redisHost: '127.0.0.1',
                redisPort: 6379,
                redisPassword: null,
                redisDatabase: 0,
                loginMaxRequests: 10,
                loginWindowSeconds: 60,
                dashboardMaxRequests: 100,
                dashboardWindowSeconds: 60,
                apiMaxRequests: 200,
                apiWindowSeconds: 60,
                authFailedMaxRequests: 10,
                authFailedWindowSeconds: 300,
            ),
            auth: new AuthConfig(
                dashboardServerId: 'dashboard',
                dashboardApiKey: 'test-key',
            ),
            composer: new ComposerConfig(
                bin: null,
                phpBin: null,
            ),
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
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
