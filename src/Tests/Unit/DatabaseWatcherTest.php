<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\DatabaseMonitoringConfig;
use Mata\Watchers\DatabaseWatcher;
use Mata\Watchers\Exceptions\DatabaseWatcherException;
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

    public function testInvalidLimitIs400(): void
    {
        $connection = new DatabaseConfig('mariadb', 'localhost', '3306', 'u', 'p');

        $this->expectExceptionObject(DatabaseWatcherException::invalidParameter('limit', 'must be an integer from 1 to 100'));

        (new DatabaseWatcher(new DatabaseMonitoringConfig($connection, ['app' => ''])))->getProcesses(['limit' => '101']);
    }
}
