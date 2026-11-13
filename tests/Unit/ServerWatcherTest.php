<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Services\FileCache;
use Mata\Tests\Support\FakeCommandRunner;
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

    public function testGetDataUsesInjectedCommandRunner(): void
    {
        $cachePath = sys_get_temp_dir() . '/mata-node-server-cache-' . bin2hex(random_bytes(6));
        mkdir($cachePath, 0777, true);
        $cache = new FileCache($cachePath);
        $cache->set('server/filecount', 5);

        $runner = new FakeCommandRunner(
            [
                "grep MemTotal /proc/meminfo | awk '{print $2}'" => "1000\n",
                "grep MemAvailable /proc/meminfo | awk '{print $2}'" => "400\n",
                "top -bn1 | grep 'Cpu(s)' | awk '{print 100 - $8}'" => "12.5\n",
                'ps -e --no-headers | wc -l' => "42\n",
            ],
            [
                FakeCommandRunner::processCountKey('php', false) => 3,
                FakeCommandRunner::processCountKey(ServerWatcher::SQL_PROCESS_NAMES) => 2,
            ]
        );

        try {
            $watcher = new ServerWatcher(__DIR__, $cache, ['check_disk_space_enabled' => 'off'], $runner);
            $data = $watcher->getData();

            $this->assertSame(5, $data['total_system_filecount']);
            $this->assertSame(1000, $data['ram_capacity']);
            $this->assertSame(600, $data['ram_used']);
            $this->assertSame('12.5', $data['cpu_usage']);
            $this->assertSame(42, $data['processes']['total']);
            $this->assertSame(3, $data['processes']['php']);
            $this->assertSame(2, $data['processes']['sql']);
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testGetDataIncludesCronjobSummary(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-server-root-' . bin2hex(random_bytes(6));
        $cachePath = sys_get_temp_dir() . '/mata-node-server-cache-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/logs/cron', 0777, true);
        mkdir($cachePath, 0777, true);
        file_put_contents($rootPath . '/logs/cron/heartbeat-daily.log', 'old');
        touch($rootPath . '/logs/cron/heartbeat-daily.log', time() - 120);
        $cache = new FileCache($cachePath);
        $cache->set('server/filecount', 5);

        $runner = new FakeCommandRunner([
            "grep MemTotal /proc/meminfo | awk '{print $2}'" => "1000\n",
            "grep MemAvailable /proc/meminfo | awk '{print $2}'" => "400\n",
            "top -bn1 | grep 'Cpu(s)' | awk '{print 100 - $8}'" => "12.5\n",
            'ps -e --no-headers | wc -l' => "42\n",
        ]);

        try {
            $watcher = new ServerWatcher($rootPath, $cache, [
                'check_disk_space_enabled' => 'off',
                'cronjob_heartbeats' => [
                    'logs/cron/heartbeat-daily.log',
                    'logs/cron/missing-heartbeat.log',
                ],
                'cronjob_heartbeat_stale_seconds' => 60,
            ], $runner);
            $data = $watcher->getData();

            $this->assertSame([
                'total' => 2,
                'working' => 0,
                'stale' => 1,
                'missing' => 1,
            ], $data['cronjobs']);
        } finally {
            $this->removeDirectory($rootPath);
            $this->removeDirectory($cachePath);
        }
    }

    public function testGetCronjobsReturnsExistingAndMissingHeartbeatFiles(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-server-root-' . bin2hex(random_bytes(6));
        $cachePath = sys_get_temp_dir() . '/mata-node-server-cache-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/logs/cron', 0777, true);
        mkdir($cachePath, 0777, true);
        file_put_contents($rootPath . '/logs/cron/heartbeat-daily.log', "line 1\nline 2\nline 3\n");
        touch($rootPath . '/logs/cron/heartbeat-daily.log', time() - 20);

        try {
            $watcher = new ServerWatcher($rootPath, new FileCache($cachePath), [
                'cronjob_heartbeats' => [
                    'logs/cron/heartbeat-daily.log',
                    'logs/cron/missing-heartbeat.log',
                ],
                'cronjob_heartbeat_stale_seconds' => 60,
                'cronjob_heartbeat_tail_line_limit' => 1,
            ]);

            $cronjobs = $watcher->getCronjobs();

            $this->assertCount(2, $cronjobs);
            $this->assertSame(0, $cronjobs[0]['index']);
            $this->assertSame('logs/cron/heartbeat-daily.log', $cronjobs[0]['path']);
            $this->assertTrue($cronjobs[0]['exists']);
            $this->assertIsString($cronjobs[0]['mtime']);
            $this->assertIsInt($cronjobs[0]['age_seconds']);
            $this->assertGreaterThanOrEqual(0, $cronjobs[0]['age_seconds']);
            $this->assertSame(strlen("line 1\nline 2\nline 3\n"), $cronjobs[0]['size']);
            $this->assertFalse($cronjobs[0]['stale']);
            $this->assertSame(['line 3'], $cronjobs[0]['content_tail']);

            $this->assertSame(1, $cronjobs[1]['index']);
            $this->assertSame('logs/cron/missing-heartbeat.log', $cronjobs[1]['path']);
            $this->assertFalse($cronjobs[1]['exists']);
            $this->assertNull($cronjobs[1]['mtime']);
            $this->assertNull($cronjobs[1]['age_seconds']);
            $this->assertSame(0, $cronjobs[1]['size']);
            $this->assertFalse($cronjobs[1]['stale']);
            $this->assertSame([], $cronjobs[1]['content_tail']);
        } finally {
            $this->removeDirectory($rootPath);
            $this->removeDirectory($cachePath);
        }
    }

    public function testGetCronjobsSupportsAbsoluteHeartbeatPaths(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-server-root-' . bin2hex(random_bytes(6));
        $cachePath = sys_get_temp_dir() . '/mata-node-server-cache-' . bin2hex(random_bytes(6));
        $heartbeatPath = $rootPath . '/logs/cron/heartbeat-absolute.log';
        mkdir(dirname($heartbeatPath), 0777, true);
        mkdir($cachePath, 0777, true);
        file_put_contents($heartbeatPath, "absolute heartbeat\n");

        try {
            $watcher = new ServerWatcher($rootPath, new FileCache($cachePath), [
                'cronjob_heartbeats' => [$heartbeatPath],
            ]);

            $cronjobs = $watcher->getCronjobs();

            $this->assertCount(1, $cronjobs);
            $this->assertSame($heartbeatPath, $cronjobs[0]['path']);
            $this->assertTrue($cronjobs[0]['exists']);
            $this->assertSame(strlen("absolute heartbeat\n"), $cronjobs[0]['size']);
            $this->assertSame(['absolute heartbeat'], $cronjobs[0]['content_tail']);
        } finally {
            $this->removeDirectory($rootPath);
            $this->removeDirectory($cachePath);
        }
    }

    public function testGetInfoReturnsDashboardContractKeys(): void
    {
        $watcher = new ServerWatcher(__DIR__, new FileCache(sys_get_temp_dir()), []);

        $info = $watcher->getInfo();

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
