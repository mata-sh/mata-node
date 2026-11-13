<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\App\LogEntryGrouper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\App\LogEntryGrouper
 *
 * @internal
 */
final class LogEntryGrouperTest extends TestCase
{
    public function testRepeatsWithDifferentIdsAndNumbersFormOneGroup(): void
    {
        $groups = LogEntryGrouper::group([
            self::entry('2026-10-25 08:01:12', 'Error', 'Order 1001 failed (request 3f2a9c1e-77b0-4a1d-9e0f-1c2d3e4f5a6b) after 1.5s in /src/Db.php:75'),
            self::entry('2026-10-25 09:00:00', 'Error', 'Order 1002 failed (request 9a8b7c6d-1111-4a1d-9e0f-1c2d3e4f5a6b) after 12.25s in /src/Db.php:75'),
            self::entry('2026-10-25 22:48:49', 'Error', 'Order 1003 failed (request 00000000-2222-4a1d-9e0f-1c2d3e4f5a6b) after 3s in /src/Db.php:75'),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['count']);
        $this->assertSame('2026-10-25 08:01:12', $groups[0]['first_seen']);
        $this->assertSame('2026-10-25 22:48:49', $groups[0]['last_seen']);
        $this->assertSame('2026-10-25 22:48:49', $groups[0]['timestamp']);
        $this->assertStringStartsWith('Order 1003 failed', $groups[0]['message']);
    }

    public function testSourceLocationAndTypeKeepMessagesApart(): void
    {
        $groups = LogEntryGrouper::group([
            self::entry('2026-10-25 08:00:00', 'Error', 'Undefined index: id in /src/Cart.php:40'),
            self::entry('2026-10-25 08:00:01', 'Error', 'Undefined index: id in /src/Cart.php:41'),
            self::entry('2026-10-25 08:00:02', 'Error', 'Division by zero in /src/Tax.php on line 12'),
            self::entry('2026-10-25 08:00:03', 'Error', 'Division by zero in /src/Tax.php on line 13'),
            self::entry('2026-10-25 08:00:04', 'Warning', 'Undefined index: id in /src/Cart.php:40'),
        ]);

        $this->assertCount(5, $groups);
    }

    public function testGroupsAreOrderedByTheirNewestEntry(): void
    {
        $groups = LogEntryGrouper::group([
            self::entry('2026-10-25 08:00:00', 'Error', 'Queue A stalled'),
            self::entry('2026-10-25 08:00:01', 'Error', 'Cache miss storm'),
            self::entry('2026-10-25 08:00:02', 'Error', 'Queue A stalled'),
        ]);

        $this->assertSame(['Cache miss storm', 'Queue A stalled'], array_column($groups, 'message'));
    }

    /** @return array{timestamp: string, type: string, message: string} */
    private static function entry(string $timestamp, string $type, string $message): array
    {
        return ['timestamp' => $timestamp, 'type' => $type, 'message' => $message];
    }
}
