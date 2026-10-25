<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\DatabasesConfigParser;
use Mata\Config\StartupConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testEmptyObjectDisablesMonitoring(): void
    {
        $this->assertNull((new DatabasesConfigParser())->parse([]));
    }

    public function testConnectionsKeepTheirOwnLogin(): void
    {
        $second = self::CONFIG;
        $second['connection']['username'] = 'other';
        $config = (new DatabasesConfigParser())->parse(['connections' => ['primary' => self::CONFIG, 'reporting' => $second]]);

        $this->assertSame('u', $config->connections['primary']->connection->username);
        $this->assertSame('other', $config->connections['reporting']->connection->username);
        $this->assertSame(['app_db', 'analytics_db'], $config->connections['reporting']->databaseNames());
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfiguration(array $config): void
    {
        $this->expectException(StartupConfigException::class);

        (new DatabasesConfigParser())->parse($config);
    }

    public static function invalidConfigs(): iterable
    {
        yield 'single connection without wrapper' => [self::CONFIG];

        yield 'missing engine' => [['connections' => ['one' => array_diff_key(self::CONFIG, ['engine' => true])]]];

        yield 'empty connections' => [['connections' => []]];

        yield 'name not usable in a URL' => [['connections' => ['Shop / prod' => self::CONFIG]]];

        yield 'pre-0.3.0 alias map' => [['legacy' => self::CONFIG]];
    }
}
