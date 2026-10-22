<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\LoggingConfig;
use Mata\Config\LogConfigurator;
use Mata\Utils\PathResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\LogConfigurator
 *
 * @internal
 */
final class LogConfiguratorTest extends TestCase
{
    public function testInvalidLevelWarningReachesTheLogFile(): void
    {
        $basePath = sys_get_temp_dir() . '/mata-node-log-configurator-' . bin2hex(random_bytes(6));
        mkdir($basePath . '/app/public', 0777, true);
        mkdir($basePath . '/app/logs');
        touch($basePath . '/app/public/index.php');

        try {
            $paths = new PathResolver($basePath . '/app/public/index.php');
            (new LogConfigurator())->createConfiguredLogger(new LoggingConfig(path: 'logs/app.log', level: 'verbose'), $paths);

            $this->assertStringContainsString(
                'app.WARNING: Invalid monolog level configured, falling back to debug {"level":"verbose"}',
                (string) file_get_contents($basePath . '/app/logs/app.log'),
            );
        } finally {
            @unlink($basePath . '/app/logs/app.log');
            unlink($basePath . '/app/public/index.php');
            rmdir($basePath . '/app/logs');
            rmdir($basePath . '/app/public');
            rmdir($basePath . '/app');
            rmdir($basePath);
        }
    }
}
