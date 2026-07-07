<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Services\FileCache;
use Mata\Watchers\ServerWatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\ServerWatcher
 *
 * @internal
 */
class ServerWatcherTest extends TestCase
{
    public function testSystemFilecountUsesFreshCache(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-server-root-' . bin2hex(random_bytes(6));
        $cachePath = sys_get_temp_dir() . '/mata-node-server-cache-' . bin2hex(random_bytes(6));
        mkdir($rootPath, 0777, true);
        mkdir($cachePath, 0777, true);
        $cache = new FileCache($cachePath);
        $cache->set('server/filecount', 123);

        try {
            $watcher = new ServerWatcher($rootPath, $cache, []);

            $this->assertSame(123, $watcher->getSystemFilecount(1800));
        } finally {
            $this->removeDirectory($rootPath);
            $this->removeDirectory($cachePath);
        }
    }

    public function testSystemFilecountRefreshesStaleCache(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-server-root-' . bin2hex(random_bytes(6));
        $cachePath = sys_get_temp_dir() . '/mata-node-server-cache-' . bin2hex(random_bytes(6));
        mkdir($rootPath, 0777, true);
        mkdir($cachePath, 0777, true);
        file_put_contents($rootPath . '/one.txt', 'one');
        $cache = new FileCache($cachePath);
        $cache->set('server/filecount', 123);
        touch($cachePath . '/server/filecount.json', time() - 3600);

        try {
            $watcher = new ServerWatcher($rootPath, $cache, []);
            $count = $watcher->getSystemFilecount(10);

            $this->assertNotSame(123, $count);
            $this->assertSame($count, json_decode((string) file_get_contents($cachePath . '/server/filecount.json'), true));
        } finally {
            $this->removeDirectory($rootPath);
            $this->removeDirectory($cachePath);
        }
    }

    public function testGetServerInfoReturnsDashboardContractKeys(): void
    {
        $watcher = new ServerWatcher(__DIR__, new FileCache(sys_get_temp_dir()), []);

        $info = $watcher->getServerInfo();

        foreach ([
            'os_name',
            'os_version',
            'kernel_version',
            'package_count',
            'php_version',
            'php_extension_count',
            'php_extensions',
            'architecture',
            'hostname',
        ] as $key) {
            $this->assertArrayHasKey($key, $info);
        }

        $this->assertIsString($info['os_name']);
        $this->assertIsString($info['os_version']);
        $this->assertIsString($info['kernel_version']);
        $this->assertIsInt($info['package_count']);
        $this->assertIsString($info['php_version']);
        $this->assertIsInt($info['php_extension_count']);
        $this->assertIsArray($info['php_extensions']);
        $this->assertIsString($info['architecture']);
        $this->assertIsString($info['hostname']);
        $this->assertSame(count($info['php_extensions']), $info['php_extension_count']);
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
