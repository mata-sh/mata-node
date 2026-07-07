<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\ServerWatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\ServerWatcher
 *
 * @internal
 */
class ServerWatcherTest extends TestCase
{
    public function testGetServerInfoReturnsDashboardContractKeys(): void
    {
        $watcher = new ServerWatcher(__DIR__, sys_get_temp_dir(), []);

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
}
