<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\DatabaseWatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\DatabaseWatcher
 *
 * @internal
 */
final class DatabaseWatcherProcessListTest extends TestCase
{
    public function testProcessRowKeepsMetadataButNotQueryText(): void
    {
        $row = DatabaseWatcher::sanitizeProcessRow([
            'id' => '42',
            'user' => 'app',
            'host' => '127.0.0.1:51234',
            'db' => 'app_production',
            'command' => 'Query',
            'time' => '3',
            'state' => 'Sending data',
            'info' => "SELECT * FROM users WHERE api_token = 'secret-token'",
        ]);

        $this->assertSame([
            'id' => 42,
            'user' => 'app',
            'host' => '127.0.0.1:51234',
            'db' => 'app_production',
            'command' => 'Query',
            'time' => 3,
            'state' => 'Sending data',
            'info' => 'SELECT',
        ], $row);
        $this->assertStringNotContainsString('secret-token', (string) json_encode($row));
    }

    public function testIdleProcessRowHasNullTextFields(): void
    {
        $row = DatabaseWatcher::sanitizeProcessRow([
            'id' => 7,
            'user' => 'app',
            'host' => 'localhost',
            'db' => null,
            'command' => 'Sleep',
            'time' => 12,
            'state' => '',
            'info' => null,
        ]);

        $this->assertNull($row['db']);
        $this->assertNull($row['info']);
        $this->assertSame('', $row['state']);
    }

    #[DataProvider('statementProvider')]
    public function testStatementTypeReturnsOnlyLeadingKeyword(?string $sql, ?string $expected): void
    {
        $this->assertSame($expected, DatabaseWatcher::statementType($sql));
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?string}>
     */
    public static function statementProvider(): iterable
    {
        yield 'null' => [null, null];

        yield 'empty' => ['', null];

        yield 'select' => ['select id from users', 'SELECT'];

        yield 'leading whitespace' => ["\n\t UPDATE users SET name = 'x'", 'UPDATE'];

        yield 'newline after keyword' => ["SELECT\n*\nFROM t", 'SELECT'];

        yield 'block comment' => ['/* app:checkout */ INSERT INTO orders VALUES (1)', 'INSERT'];

        yield 'line comments' => ["-- trace\n# more\nDELETE FROM sessions", 'DELETE'];

        yield 'credentials' => ["ALTER USER 'app'@'%' IDENTIFIED BY 'hunter2'", 'ALTER'];

        yield 'no keyword' => ['(SELECT 1)', null];
    }
}
