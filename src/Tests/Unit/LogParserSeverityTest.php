<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\LogParsers\AbstractLogParser;
use Mata\Watchers\LogParsers\JsonParser;
use Mata\Watchers\LogParsers\MonologParser;
use Mata\Watchers\LogParsers\PhpErrorLogParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `/apps` counts and the types of the `/logs` entries agree for every built-in parser.
 *
 * @covers \Mata\Watchers\LogParsers\AbstractLogParser
 * @covers \Mata\Watchers\LogParsers\JsonParser
 * @covers \Mata\Watchers\LogParsers\MonologParser
 * @covers \Mata\Watchers\LogParsers\PhpErrorLogParser
 *
 * @internal
 */
final class LogParserSeverityTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/logs';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * @param int $fatal    expected count, -1 for a fixture checked only against its parsed entries
     * @param int $warnings expected count, -1 for a fixture checked only against its parsed entries
     */
    #[DataProvider('logs')]
    public function testCountsMatchTheParsedEntryTypes(AbstractLogParser $parser, string $contents, int $fatal, int $warnings): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'mata-severity-');
        $this->tempFiles[] = $logFile;
        file_put_contents($logFile, $contents . "\n");

        $result = $parser->analyzeLog($logFile);
        $types = array_count_values(array_column($parser->parseLines(explode("\n", $contents)), 'type'));

        $this->assertSame($types['Error'] ?? 0, $result->fatal, 'fatal count vs Error entries');
        $this->assertSame($types['Warning'] ?? 0, $result->warnings, 'warning count vs Warning entries');

        if ($fatal >= 0) {
            $this->assertSame($fatal, $result->fatal);
            $this->assertSame($warnings, $result->warnings);
        }
    }

    /**
     * @return iterable<string, array{AbstractLogParser, string, int, int}>
     */
    public static function logs(): iterable
    {
        yield 'json fixture' => [new JsonParser(), (string) file_get_contents(self::FIXTURES . '/json.log'), -1, -1];

        yield 'monolog fixture' => [new MonologParser(), (string) file_get_contents(self::FIXTURES . '/monolog.log'), -1, -1];

        yield 'php fixture' => [new PhpErrorLogParser(), (string) file_get_contents(self::FIXTURES . '/php_error.log'), -1, -1];

        yield 'json level with spaces' => [new JsonParser(), '{"level": "error", "message": "spaced"}', 1, 0];

        yield 'json severity key' => [new JsonParser(), '{"severity":"error","message":"severity key"}', 1, 0];

        yield 'json uppercase level' => [new JsonParser(), '{"level":"ERROR","message":"upper"}', 1, 0];

        yield 'json warn' => [new JsonParser(), '{"level":"warn","message":"warn"}', 0, 1];

        yield 'json error level in context' => [new JsonParser(), '{"level":"info","message":"ok","context":{"level":"error"}}', 0, 0];

        yield 'json error word in message' => [new JsonParser(), '{"level":"info","message":"no error here"}', 0, 0];

        yield 'json from monolog' => [new JsonParser(), "{\"message\":\"boom\",\"context\":{},\"level\":400,\"level_name\":\"ERROR\",\"channel\":\"app\",\"datetime\":\"2026-01-18T10:30:45.123456+00:00\",\"extra\":{}}\n{\"message\":\"slow\",\"level\":300,\"level_name\":\"WARNING\",\"channel\":\"app\",\"datetime\":\"2026-01-18T10:30:46.000000+00:00\"}", 1, 1];

        yield 'json numeric level' => [new JsonParser(), '{"level":50,"msg":"pino error"}', 0, 0];

        yield 'monolog error level in message' => [new MonologParser(), '[2026-01-18 10:30:45] app.INFO: retrying after app.ERROR: timeout [] []', 0, 0];

        yield 'monolog error level in stack trace' => [new MonologParser(), "[2026-01-18 10:30:45] app.INFO: ok [] []\n#0 /src/Foo.php(12): Bar->ERROR: x", 0, 0];

        yield 'monolog default timestamp' => [new MonologParser(), "[2026-01-18T10:30:45.123456+00:00] app.ERROR: boom [] []\n[2026-01-18T10:30:46.000000Z] app.WARNING: slow [] []", 1, 1];

        yield 'php stack frame in an error path' => [new PhpErrorLogParser(), "[18-Jan-2026 10:30:45 UTC] PHP Fatal error:  Uncaught Exception\n[18-Jan-2026 10:30:45 UTC] PHP Stack trace:\n[18-Jan-2026 10:30:45 UTC] PHP   1. {main}() /var/www/error_handler.php:0", 1, 0];

        yield 'php notice' => [new PhpErrorLogParser(), '[18-Jan-2026 10:30:45 UTC] PHP Notice:  Undefined index: error', 0, 1];
    }

    public function testMonologDefaultTimestampIsDetectedAndParsed(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'mata-severity-');
        $this->tempFiles[] = $logFile;
        file_put_contents($logFile, str_repeat("[2026-01-18T10:30:45.123456+00:00] app.ERROR: boom [] []\n", 3));

        $parser = new MonologParser();
        $entries = $parser->parseLines((array) file($logFile, FILE_IGNORE_NEW_LINES));

        $this->assertTrue($parser->detect($logFile));
        $this->assertCount(3, $entries);
        $this->assertSame('2026-01-18 10:30:45', $entries[0]['timestamp']);
        $this->assertSame('Error', $entries[0]['type']);
    }
}
