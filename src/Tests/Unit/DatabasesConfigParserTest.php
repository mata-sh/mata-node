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
    private const CONFIG = [
        'engine' => 'mariadb',
        'connection' => ['host' => 'localhost', 'port' => 3306, 'username' => 'u', 'password' => 'p'],
        'databases' => ['app_db' => 'Main application', 'analytics_db' => ''],
    ];

    public function testParsesConfig(): void
    {
        $config = (new DatabasesConfigParser())->parse(self::CONFIG);

        $this->assertSame('3306', $config?->connection->port);
        $this->assertSame(['app_db', 'analytics_db'], $config->databaseNames());
    }

    public function testRequiresEngine(): void
    {
        $this->expectException(StartupConfigException::class);

        (new DatabasesConfigParser())->parse(array_diff_key(self::CONFIG, ['engine' => true]));
    }
}
