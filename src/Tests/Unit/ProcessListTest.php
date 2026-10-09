<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\Database\ProcessList;
use Mata\Watchers\Database\ProcessRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\Database\ProcessList
 *
 * @internal
 */
final class ProcessListTest extends TestCase
{
    public function testFiltersGroupsSortsAndTruncates(): void
    {
        $row = static fn (int $id, ?string $db, int $time, bool $running): ProcessRow => new ProcessRow($id, 'u', 'h', $db, null, $time, null, null, $running);

        $result = ProcessList::build([
            $row(1, 'app', 10, true),
            $row(2, 'app', 4, false),
            $row(3, 'app', 10, true),
            $row(4, 'other_app', 99, true),
            $row(5, null, 50, true),
        ], ['app', 'empty'], null, true, 1);

        $this->assertSame([
            ['database' => 'app', 'connections' => 3, 'running' => 2],
            ['database' => 'empty', 'connections' => 0, 'running' => 0],
        ], $result['summary_by_database']);
        $this->assertSame([1], array_column($result['processes'], 'id'));
        $this->assertTrue($result['truncated']);
    }
}
