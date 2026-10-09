<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Tests\Support\FakeDatabase;
use Mata\Watchers\Database\PostgresDriver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\Database\PostgresDriver
 *
 * @internal
 */
final class PostgresDriverTest extends TestCase
{
    public function testOverviewMapsActivityAndLeavesUnsupportedFieldsNull(): void
    {
        $status = (new PostgresDriver())->overview(new FakeDatabase([
            'SHOW max_connections' => [['max_connections' => '100']],
            'pg_stat_activity' => [['connections' => 12, 'running' => 2, 'uptime_seconds' => 864000]],
        ]));

        $this->assertSame(100, $status->maxConnections);
        $this->assertSame(12, $status->connections);
        $this->assertSame(864000, $status->uptimeSeconds);
        $this->assertNull($status->maxUsedConnections);
    }

    public function testProcessesKeepOnlyStatementKeyword(): void
    {
        $rows = (new PostgresDriver())->processes(new FakeDatabase(['pg_stat_activity' => [
            ['pid' => 42, 'datname' => 'app', 'state' => 'active', 'time' => 3, 'query' => "/* x */ SELECT * FROM users WHERE token = 'secret'"],
        ]]));

        $this->assertSame('SELECT', $rows[0]->statement);
        $this->assertTrue($rows[0]->running);
    }
}
