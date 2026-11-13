<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Tests\Support\FakeDatabase;
use Mata\Watchers\Database\MariaDbDriver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\Database\MariaDbDriver
 *
 * @internal
 */
final class MariaDbDriverTest extends TestCase
{
    public function testOverviewMapsStatusVariables(): void
    {
        $status = (new MariaDbDriver())->overview(new FakeDatabase([
            'SHOW GLOBAL VARIABLES' => [['Variable_name' => 'max_connections', 'Value' => '151']],
            'SHOW GLOBAL STATUS' => [
                ['Variable_name' => 'Threads_connected', 'Value' => '12'],
                ['Variable_name' => 'Threads_running', 'Value' => '2'],
                ['Variable_name' => 'Max_used_connections', 'Value' => '43'],
                ['Variable_name' => 'Connection_errors_max_connections', 'Value' => '0'],
                ['Variable_name' => 'Aborted_connects', 'Value' => '3'],
                ['Variable_name' => 'Uptime', 'Value' => '864000'],
            ],
        ]));

        $this->assertSame(151, $status->maxConnections);
        $this->assertSame(12, $status->connections);
        $this->assertSame(864000, $status->uptimeSeconds);
    }

    public function testProcessesKeepOnlyStatementKeyword(): void
    {
        $rows = (new MariaDbDriver())->processes(new FakeDatabase(['information_schema.processlist' => [
            ['id' => '42', 'db' => 'app', 'command' => 'Query', 'time' => '3', 'info' => "/* x */ SELECT * FROM users WHERE token = 'secret'"],
        ]]));

        $this->assertSame('SELECT', $rows[0]->statement);
        $this->assertTrue($rows[0]->running);
    }
}
