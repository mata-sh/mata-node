<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\DatabasesConfigParser;
use Mata\Config\StartupConfigException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\DatabasesConfigParser
 *
 * @internal
 */
final class DatabasesConfigParserTest extends TestCase
{
    private const CONNECTION = ['host' => 'localhost', 'port' => '3306', 'username' => 'u', 'password' => 'p', 'database' => 'app'];

    public function testParsesMonitoringBlock(): void
    {
        $config = (new DatabasesConfigParser())->parse([
            'connections' => ['primary' => self::CONNECTION],
            'monitoring' => ['instance' => 'main', 'connection' => 'primary'],
        ]);

        $this->assertSame('mariadb', $config?->connection->engine);
        $this->assertSame(['app'], $config->schemaNames());
    }

    public function testRejectsOldFlatFormat(): void
    {
        $this->expectException(StartupConfigException::class);

        (new DatabasesConfigParser())->parse(['primary' => self::CONNECTION]);
    }
}
