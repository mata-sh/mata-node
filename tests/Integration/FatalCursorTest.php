<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use LogicException;
use Mata\Utils\Tools;
use Mata\Watchers\App\FatalCursor;
use Mata\Watchers\AppWatcher;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fatal cursor semantics across the supported log formats.
 *
 * @covers \Mata\Watchers\App\FatalCursor
 * @covers \Mata\Watchers\App\LogInspector
 * @covers \Mata\Watchers\LogParsers\AbstractLogParser
 *
 * @internal
 */
final class FatalCursorTest extends TestCase
{
    private const APP = 'testapp';

    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/mata-node-fatal-cursor-' . bin2hex(random_bytes(6));
        mkdir($this->rootPath . '/app/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootPath);
    }

    #[DataProvider('logFormatProvider')]
    public function testZeroFatalsReturnsNullCursor(string $format): void
    {
        $this->writeLog($format, [$this->warningLine($format, 'first'), $this->infoLine($format, 'second')]);

        $status = $this->logStatus($format);

        $this->assertSame(0, $status['fatal']);
        $this->assertNull($status['fatal_cursor']);
    }

    #[DataProvider('logFormatProvider')]
    public function testPositiveFatalCountReturnsVersionedCursor(string $format): void
    {
        $this->writeLog($format, [$this->fatalLine($format, 'boom')]);

        $status = $this->logStatus($format);

        $this->assertSame(1, $status['fatal']);
        $this->assertIsString($status['fatal_cursor']);
        $this->assertMatchesRegularExpression('/^v1:[0-9a-f]{64}$/', $status['fatal_cursor']);
        $this->assertTrue(FatalCursor::isValid($status['fatal_cursor']));
    }

    #[DataProvider('logFormatProvider')]
    public function testUnchangedInputReturnsTheSameCursorForRepeatedCallsAndFreshWatchers(string $format): void
    {
        $this->writeLog($format, [$this->fatalLine($format, 'boom'), $this->warningLine($format, 'careful')]);

        $watcher = $this->createAppWatcher($format);
        $first = $watcher->getAppInfo(self::APP)['status']['fatal_cursor'];
        $second = $watcher->getAppInfo(self::APP)['status']['fatal_cursor'];
        $third = $this->createAppWatcher($format)->getAppInfo(self::APP)['status']['fatal_cursor'];

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    #[DataProvider('logFormatProvider')]
    public function testAppendingNonFatalInputDoesNotChangeTheCursor(string $format): void
    {
        $lines = [$this->fatalLine($format, 'boom')];
        $this->writeLog($format, $lines);
        $before = $this->cursor($format);

        $lines[] = $this->warningLine($format, 'careful');
        $lines[] = $this->infoLine($format, 'just so you know');
        $this->writeLog($format, $lines);

        $this->assertNotNull($before);
        $this->assertSame($before, $this->cursor($format));
        $this->assertSame(1, $this->logStatus($format)['fatal']);
    }

    #[DataProvider('logFormatProvider')]
    public function testAppendingOneFatalChangesTheCursor(string $format): void
    {
        $lines = [$this->fatalLine($format, 'boom')];
        $this->writeLog($format, $lines);
        $before = $this->cursor($format);

        $lines[] = $this->fatalLine($format, 'another failure');
        $this->writeLog($format, $lines);

        $this->assertNotSame($before, $this->cursor($format));
        $this->assertSame(2, $this->logStatus($format)['fatal']);
    }

    #[DataProvider('logFormatProvider')]
    public function testAppendingAnIdenticalFatalChangesTheCursor(string $format): void
    {
        $lines = [$this->fatalLine($format, 'boom')];
        $this->writeLog($format, $lines);
        $before = $this->cursor($format);

        $lines[] = $this->fatalLine($format, 'boom');
        $this->writeLog($format, $lines);

        $this->assertNotSame($before, $this->cursor($format));
    }

    #[DataProvider('logFormatProvider')]
    public function testChangingAFatalMessageChangesTheCursor(string $format): void
    {
        $this->writeLog($format, [$this->fatalLine($format, 'boom')]);
        $before = $this->cursor($format);

        $this->writeLog($format, [$this->fatalLine($format, 'bang')]);

        $this->assertNotSame($before, $this->cursor($format));
    }

    #[DataProvider('logFormatProvider')]
    public function testChangingAFatalTimestampChangesTheCursor(string $format): void
    {
        $this->writeLog($format, [$this->fatalLine($format, 'boom')]);
        $before = $this->cursor($format);

        $this->writeLog($format, [$this->fatalLine($format, 'boom', late: true)]);

        $this->assertNotSame($before, $this->cursor($format));
    }

    #[DataProvider('logFormatProvider')]
    public function testReorderingFatalEntriesChangesTheCursor(string $format): void
    {
        $first = $this->fatalLine($format, 'first failure');
        $second = $this->fatalLine($format, 'second failure');

        $this->writeLog($format, [$first, $second]);
        $before = $this->cursor($format);

        $this->writeLog($format, [$second, $first]);

        $this->assertNotSame($before, $this->cursor($format));
    }

    public function testChangingAMultilineStackTraceChangesTheCursor(): void
    {
        $entry = "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: Uncaught PDOException in /app/Db.php:23\nStack trace:\n#0 /app/Db.php(23): PDO->__construct()\n#1 {main}";
        $this->writeLog('php_error_log', [$entry]);
        $before = $this->cursor('php_error_log');

        $changed = str_replace('#1 {main}', '#1 /app/boot.php(9): Db::connect()', $entry);
        $this->writeLog('php_error_log', [$changed]);

        $this->assertNotNull($before);
        $this->assertNotSame($before, $this->cursor('php_error_log'));
    }

    public function testChangingAMonologContextBlockChangesTheCursor(): void
    {
        $entry = "[2026-07-19 12:00:00] app.ERROR: Payment failed {\"id\":1}\n    at PaymentService->charge() in /app/Payment.php:45";
        $this->writeLog('monolog', [$entry]);
        $before = $this->cursor('monolog');

        $changed = str_replace('/app/Payment.php:45', '/app/Payment.php:46', $entry);
        $this->writeLog('monolog', [$changed]);

        $this->assertNotNull($before);
        $this->assertNotSame($before, $this->cursor('monolog'));
    }

    public function testChangingAJsonContextChangesTheCursor(): void
    {
        $this->writeLog('json', ['{"timestamp":"2026-07-19T12:00:00Z","level":"error","message":"boom","context":{"attempt":1}}']);
        $before = $this->cursor('json');

        $this->writeLog('json', ['{"timestamp":"2026-07-19T12:00:00Z","level":"error","message":"boom","context":{"attempt":2}}']);

        $this->assertNotNull($before);
        $this->assertNotSame($before, $this->cursor('json'));
    }

    public function testJsonKeyOrderDoesNotChangeTheCursor(): void
    {
        $this->writeLog('json', ['{"timestamp":"2026-07-19T12:00:00Z","level":"error","message":"boom","context":{"attempt":1,"host":"db"}}']);
        $before = $this->cursor('json');

        $this->writeLog('json', ['{"context":{"host":"db","attempt":1},"message":"boom","level":"error","timestamp":"2026-07-19T12:00:00Z"}']);

        $this->assertNotNull($before);
        $this->assertSame($before, $this->cursor('json'));
    }

    #[DataProvider('logFormatProvider')]
    public function testRotationToANewFileGenerationChangesTheCursor(string $format): void
    {
        $lines = [$this->fatalLine($format, 'boom')];
        $this->writeLog($format, $lines);
        $before = $this->cursor($format);

        $logFile = $this->logFile($format);
        rename($logFile, $logFile . '.1');
        $this->writeLog($format, $lines);

        $this->assertNotNull($before);
        $this->assertNotSame($before, $this->cursor($format));
        $this->assertSame(1, $this->logStatus($format)['fatal']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function logFormatProvider(): array
    {
        return [
            'php_error_log' => ['php_error_log'],
            'monolog' => ['monolog'],
            'json' => ['json'],
        ];
    }

    public function testMultipleLogsAggregateIndependentlyOfMapIterationOrder(): void
    {
        $first = 'app/logs/first.log';
        $second = 'app/logs/second.log';
        file_put_contents($this->rootPath . '/' . $first, $this->fatalLine('php_error_log', 'first failure') . "\n");
        file_put_contents($this->rootPath . '/' . $second, $this->fatalLine('php_error_log', 'second failure') . "\n");

        $ascending = $this->cursorForLogs([$first, $second]);
        $descending = $this->cursorForLogs([$second, $first]);

        $this->assertNotNull($ascending);
        $this->assertSame($ascending, $descending);
        $this->assertSame(2, $this->statsForLogs([$second, $first])['fatal']);
    }

    public function testCursorChangesWhenOnlyOneOfSeveralLogsChanges(): void
    {
        $first = 'app/logs/first.log';
        $second = 'app/logs/second.log';
        file_put_contents($this->rootPath . '/' . $first, $this->fatalLine('php_error_log', 'first failure') . "\n");
        file_put_contents($this->rootPath . '/' . $second, $this->fatalLine('php_error_log', 'second failure') . "\n");
        $before = $this->cursorForLogs([$first, $second]);

        file_put_contents(
            $this->rootPath . '/' . $second,
            $this->fatalLine('php_error_log', 'second failure') . "\n" . $this->fatalLine('php_error_log', 'third failure') . "\n"
        );

        $this->assertNotSame($before, $this->cursorForLogs([$first, $second]));
    }

    public function testUnreadableLogIsWarnedAboutAndContributesNothing(): void
    {
        file_put_contents($this->rootPath . '/app/logs/present.log', $this->fatalLine('php_error_log', 'boom') . "\n");

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $watcher = $this->createAppWatcher('php_error_log', ['app/logs/present.log', 'app/logs/missing.log'], $logger);
        $status = $watcher->getAppInfo(self::APP)['status'];

        $this->assertSame(1, $status['fatal']);
        $this->assertSame($this->cursorForLogs(['app/logs/present.log']), $status['fatal_cursor']);
        $this->assertTrue($handler->hasWarningThatContains('Log file not found or unreadable'));
    }

    public function testLongJsonRecordIsCountedAndCanonicalizedInFull(): void
    {
        $padding = str_repeat('p', 200000);
        $record = ['timestamp' => '2026-07-19T12:00:00Z', 'message' => 'boom', 'padding' => $padding, 'level' => 'error'];

        $this->writeLog('json', [(string) json_encode($record)]);
        $status = $this->logStatus('json');
        $before = $status['fatal_cursor'];

        $this->assertSame(1, $status['fatal'], 'A severity marker beyond any read block must still be counted.');
        $this->assertNotNull($before);

        // Same record, different key order: canonicalization must see all of it.
        $this->writeLog('json', [(string) json_encode(array_reverse($record))]);
        $this->assertSame($before, $this->cursor('json'));

        // A change deep inside the record must still be visible.
        $record['padding'] = str_repeat('p', 199999) . 'q';
        $this->writeLog('json', [(string) json_encode($record)]);
        $this->assertNotSame($before, $this->cursor('json'));
    }

    public function testLongPhpErrorEntryIsFingerprintedBeyondTheReadBlock(): void
    {
        $entry = '[19-Jul-2026 12:00:00 UTC] PHP Fatal error: Uncaught RuntimeException: ' . str_repeat('a', 200000);
        $this->writeLog('php_error_log', [$entry]);
        $status = $this->logStatus('php_error_log');
        $before = $status['fatal_cursor'];

        $this->assertSame(1, $status['fatal']);
        $this->assertNotNull($before);

        $this->writeLog('php_error_log', [substr($entry, 0, -1) . 'b']);

        $this->assertNotSame($before, $this->cursor('php_error_log'));
    }

    public function testJsonRecordLongerThanTheLineBufferHasNoLevelAndIsNotCounted(): void
    {
        $record = [
            'timestamp' => '2026-07-19T12:00:00Z',
            'padding' => str_repeat('p', 6 * 1024 * 1024),
            'level' => 'error',
            'message' => 'boom',
        ];

        $this->writeLog('json', [(string) json_encode($record), $this->infoLine('json', 'noise')]);

        $status = $this->logStatus('json');

        $this->assertSame(0, $status['fatal'], 'Only the buffered head of the record is read, and it does not decode.');
        $this->assertSame(2, $status['total']);
        $this->assertNull($status['fatal_cursor']);
    }

    public function testScanningALargeLogUsesBoundedMemory(): void
    {
        $logFile = $this->rootPath . '/app/logs/large.log';
        $handle = fopen($logFile, 'wb');
        self::assertIsResource($handle);

        $fatal = '[19-Jul-2026 12:00:00 UTC] PHP Fatal error: Uncaught RuntimeException: payload ' . str_repeat('x', 400) . "\n";
        $warning = '[19-Jul-2026 12:00:00 UTC] PHP Warning: noise ' . str_repeat('y', 400) . "\n";
        for ($i = 0; $i < 12000; ++$i) {
            fwrite($handle, $fatal);
            fwrite($handle, $warning);
        }
        fclose($handle);

        $this->assertGreaterThan(10 * 1024 * 1024, (int) filesize($logFile));

        $watcher = $this->createAppWatcher('php_error_log', ['app/logs/large.log']);
        gc_collect_cycles();
        $before = memory_get_peak_usage(true);

        $status = $watcher->getAppInfo(self::APP)['status'];

        $growth = memory_get_peak_usage(true) - $before;

        $this->assertSame(12000, $status['fatal']);
        $this->assertSame(12000, $status['warnings']);
        $this->assertSame(24000, $status['total']);
        $this->assertNotNull($status['fatal_cursor']);
        $this->assertLessThan(4 * 1024 * 1024, $growth, 'Log analysis must not scale memory with log size.');
    }

    /**
     * @param array<int, string> $lines
     */
    private function writeLog(string $format, array $lines): void
    {
        file_put_contents($this->logFile($format), implode("\n", $lines) . "\n");
    }

    private function logFile(string $format): string
    {
        return $this->rootPath . '/' . $this->logPath($format);
    }

    private function logPath(string $format): string
    {
        return 'app/logs/' . $format . '.log';
    }

    /**
     * @return array{fatal: int, warnings: int, total: int, fatal_cursor: null|string}
     */
    private function logStatus(string $format): array
    {
        return $this->createAppWatcher($format)->getAppInfo(self::APP)['status'];
    }

    private function cursor(string $format): ?string
    {
        return $this->logStatus($format)['fatal_cursor'];
    }

    /**
     * @param array<int, string> $logPaths
     */
    private function cursorForLogs(array $logPaths): ?string
    {
        return $this->statsForLogs($logPaths)['fatal_cursor'];
    }

    /**
     * @param array<int, string> $logPaths
     *
     * @return array{fatal: int, warnings: int, total: int, fatal_cursor: null|string}
     */
    private function statsForLogs(array $logPaths): array
    {
        return $this->createAppWatcher('php_error_log', $logPaths)->getAppInfo(self::APP)['status'];
    }

    /**
     * @param null|array<int, string> $logPaths
     */
    private function createAppWatcher(string $format, ?array $logPaths = null, ?LoggerInterface $logger = null): AppWatcher
    {
        return new AppWatcher(
            new Tools(),
            $logger ?? new NullLogger(),
            $this->rootPath,
            [
                self::APP => [
                    'title' => 'Test App',
                    'url' => 'https://example.com/test',
                    'path' => 'app',
                    'env' => 'test',
                    'logs' => [
                        [
                            'type' => $format,
                            'paths' => $logPaths ?? [$this->logPath($format)],
                        ],
                    ],
                ],
            ],
            ['errorlog_readout_limit' => 50],
        );
    }

    private function fatalLine(string $format, string $message, bool $late = false): string
    {
        $time = $late ? '13:00:00' : '12:00:00';

        return match ($format) {
            'php_error_log' => "[19-Jul-2026 {$time} UTC] PHP Fatal error: {$message}",
            'monolog' => "[2026-07-19 {$time}] app.ERROR: {$message}",
            'json' => '{"timestamp":"2026-07-19T' . $time . 'Z","level":"error","message":"' . $message . '"}',
            default => throw new LogicException('Unknown format ' . $format),
        };
    }

    private function warningLine(string $format, string $message): string
    {
        return match ($format) {
            'php_error_log' => "[19-Jul-2026 12:00:00 UTC] PHP Warning: {$message}",
            'monolog' => "[2026-07-19 12:00:00] app.WARNING: {$message}",
            'json' => '{"timestamp":"2026-07-19T12:00:00Z","level":"warning","message":"' . $message . '"}',
            default => throw new LogicException('Unknown format ' . $format),
        };
    }

    private function infoLine(string $format, string $message): string
    {
        return match ($format) {
            'php_error_log' => "[19-Jul-2026 12:00:00 UTC] PHP Notice: {$message}",
            'monolog' => "[2026-07-19 12:00:00] app.INFO: {$message}",
            'json' => '{"timestamp":"2026-07-19T12:00:00Z","level":"info","message":"' . $message . '"}',
            default => throw new LogicException('Unknown format ' . $format),
        };
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
