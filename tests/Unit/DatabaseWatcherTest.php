<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\DatabasesConfigParser;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\DatabaseMonitoringConfig;
use Mata\Core\DatabaseInterface;
use Mata\Tests\Support\FakeDatabase;
use Mata\Watchers\DatabaseWatcher;
use Mata\Watchers\Exceptions\DatabaseWatcherException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\DatabaseWatcher
 *
 * @internal
 */
final class DatabaseWatcherTest extends TestCase
{
    public function testNotConfiguredIs404(): void
    {
        $this->expectExceptionObject(DatabaseWatcherException::notConfigured());

        (new DatabaseWatcher(null))->getOverview();
    }

    public function testEachConnectionLogsInWithItsOwnCredentials(): void
    {
        $logins = [];
        $watcher = new DatabaseWatcher($this->config(), connector: static function (string $dsn, DatabaseConfig $config) use (&$logins): DatabaseInterface {
            $logins[] = $config->username;

            return new FakeDatabase(['processlist' => [
                ['id' => $config->username === 'one' ? 1 : 2, 'db' => 'shared', 'command' => 'Query', 'time' => 10],
            ]]);
        });

        $this->assertSame([1], array_column($watcher->getProcesses(['connection' => 'one'])['processes'], 'id'));
        $this->assertSame([2], array_column($watcher->getProcesses(['connection' => 'two'])['processes'], 'id'));
        $watcher->getProcesses(['connection' => 'one']);
        $this->assertSame(['one', 'two'], $logins);
    }

    public function testFailedConnectionKeepsOthersAndHidesDetails(): void
    {
        $watcher = new DatabaseWatcher($this->config(), connector: static function (string $dsn, DatabaseConfig $config): DatabaseInterface {
            if ($config->username === 'one') {
                throw new PDOException('Access denied for password-one');
            }

            $status = ['Threads_connected', 'Threads_running', 'Max_used_connections', 'Connection_errors_max_connections', 'Aborted_connects', 'Uptime'];

            return new FakeDatabase([
                'VARIABLES' => [['Variable_name' => 'max_connections', 'Value' => '151']],
                'STATUS' => array_map(static fn (string $name): array => ['Variable_name' => $name, 'Value' => '2'], $status),
            ]);
        });

        [$failed, $healthy] = $watcher->getOverview()['connections'];

        $this->assertNull($failed['status']);
        $this->assertSame(503, $failed['error']['status_code']);
        $this->assertSame([['name' => 'shared', 'description' => 'First']], $failed['databases']);
        $this->assertNull($healthy['error']);
        $this->assertStringNotContainsString('password-one', json_encode([$failed, $healthy], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('invalidSelections')]
    public function testRejectsInvalidSelection(array $query, int $status): void
    {
        $watcher = new DatabaseWatcher($this->config(), connector: fn (): DatabaseInterface => $this->fail('Invalid request must not connect'));

        try {
            $watcher->getProcesses($query);
            $this->fail('Expected request rejection');
        } catch (DatabaseWatcherException $error) {
            $this->assertSame($status, $error->getHttpStatus());
        }
    }

    public static function invalidSelections(): iterable
    {
        yield 'connection omitted' => [[], 400];

        yield 'unknown connection' => [['connection' => 'missing'], 404];

        yield 'database of another connection' => [['connection' => 'one', 'database' => 'only-two'], 404];

        yield 'limit out of range' => [['connection' => 'one', 'limit' => '101'], 400];
    }

    private function config(): DatabaseMonitoringConfig
    {
        return (new DatabasesConfigParser())->parse(['connections' => [
            'one' => [
                'engine' => 'mariadb',
                'connection' => ['host' => 'same-host', 'port' => 3306, 'username' => 'one', 'password' => 'password-one'],
                'databases' => ['shared' => 'First'],
            ],
            'two' => [
                'engine' => 'mariadb',
                'connection' => ['host' => 'same-host', 'port' => 3306, 'username' => 'two', 'password' => 'password-two'],
                'databases' => ['shared' => 'Second', 'only-two' => 'Private'],
            ],
        ]]);
    }
}
