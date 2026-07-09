<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Services\Command\ShellCommands;
use Mata\Tests\Support\FakeCommandRunner;
use Mata\Watchers\LogParsers\JsonParser;
use Mata\Watchers\LogParsers\LogParserFactory;
use Mata\Watchers\LogParsers\MonologParser;
use Mata\Watchers\LogParsers\PhpErrorLogParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LogParserFactory.
 *
 * @covers \Mata\Watchers\LogParsers\LogParserFactory
 *
 * @internal
 */
class LogParserFactoryTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../Fixtures/logs';

        // Clear factory caches before each test
        LogParserFactory::clearCache();
    }

    protected function tearDown(): void
    {
        // Clear caches after tests
        LogParserFactory::clearCache();
    }

    public function testGetAvailableParsersReturnsAllParsers(): void
    {
        $parsers = LogParserFactory::getAvailableParsers();

        $this->assertIsArray($parsers);
        $this->assertContains('json', $parsers);
        $this->assertContains('monolog', $parsers);
        $this->assertContains('php_error_log', $parsers);
    }

    public function testGetAvailableParsersDoesNotIncludeFactory(): void
    {
        $parsers = LogParserFactory::getAvailableParsers();

        // Factory itself should not be in the list
        $this->assertNotContains('LogParserFactory', $parsers);
    }

    public function testGetParserReturnsPhpErrorLogParser(): void
    {
        $parser = LogParserFactory::getParser('php_error_log');

        $this->assertNotNull($parser);
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser);
    }

    public function testGetParserReturnsMonologParser(): void
    {
        $parser = LogParserFactory::getParser('monolog');

        $this->assertNotNull($parser);
        $this->assertInstanceOf(MonologParser::class, $parser);
    }

    public function testGetParserReturnsJsonParser(): void
    {
        $parser = LogParserFactory::getParser('json');

        $this->assertNotNull($parser);
        $this->assertInstanceOf(JsonParser::class, $parser);
    }

    public function testGetParserReturnsNullForUnknownFormat(): void
    {
        $parser = LogParserFactory::getParser('unknown_format');

        $this->assertNull($parser);
    }

    public function testGetParserReturnsNullForEmptyString(): void
    {
        $parser = LogParserFactory::getParser('');

        $this->assertNull($parser);
    }

    public function testDetectFormatDetectsPhpErrorLog(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parser = LogParserFactory::detectFormat($logFile);

        $this->assertInstanceOf(PhpErrorLogParser::class, $parser);
        $this->assertEquals('php_error_log', $parser->getName());
    }

    public function testDetectFormatDetectsMonolog(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parser = LogParserFactory::detectFormat($logFile);

        $this->assertInstanceOf(MonologParser::class, $parser);
        $this->assertEquals('monolog', $parser->getName());
    }

    public function testDetectFormatDetectsJson(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parser = LogParserFactory::detectFormat($logFile);

        $this->assertInstanceOf(JsonParser::class, $parser);
        $this->assertEquals('json', $parser->getName());
    }

    public function testDetectFormatFallsBackToPhpErrorLogForUnknown(): void
    {
        $logFile = $this->fixturesPath . '/mixed.log';
        $parser = LogParserFactory::detectFormat($logFile);

        // Should fall back to PHP error_log parser
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser);
    }

    public function testDetectFormatFallsBackToPhpErrorLogForNonExistent(): void
    {
        $logFile = $this->fixturesPath . '/nonexistent.log';
        $parser = LogParserFactory::detectFormat($logFile);

        // Should fall back to PHP error_log parser
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser);
    }

    public function testDetectFormatCachedClassUsesNewRunnerAfterSwap(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'mata_log_parser_');
        self::assertNotFalse($logFile);

        $jsonLine = '{"timestamp":"2026-01-01T00:00:00Z","level":"error","message":"x"}';
        $jsonLog = implode("\n", array_fill(0, 10, $jsonLine)) . "\n";
        file_put_contents($logFile, $jsonLog);

        $headCommand = ShellCommands::head($logFile, 10);
        $errorPattern = '"level":"error"\|"level":"critical"\|"level":"emergency"\|"level":"alert"';
        $errorCommand = ShellCommands::grepCount($errorPattern, $logFile);
        $warningPattern = '"level":"warning"';
        $warningCommand = ShellCommands::grepCount($warningPattern, $logFile);
        $totalCommand = ShellCommands::lineCount($logFile);

        $runnerA = new FakeCommandRunner([$headCommand => $jsonLog]);
        $runnerB = new FakeCommandRunner([
            $errorCommand => '10',
            $warningCommand => '0',
            $totalCommand => '10',
        ]);

        try {
            $parserA = LogParserFactory::detectFormat($logFile, null, $runnerA);
            $parserB = LogParserFactory::detectFormat($logFile, null, $runnerB);

            $this->assertNotSame($parserA, $parserB);
            $this->assertSame($parserA::class, $parserB::class);
            $this->assertInstanceOf(JsonParser::class, $parserB);
            $this->assertContains($headCommand, $runnerA->commands);

            $this->assertSame([
                'fatal' => 10,
                'warnings' => 0,
                'total' => 10,
            ], $parserB->analyzeLog($logFile));
            $this->assertContains($errorCommand, $runnerB->commands);
            $this->assertContains($warningCommand, $runnerB->commands);
            $this->assertContains($totalCommand, $runnerB->commands);
        } finally {
            unlink($logFile);
        }
    }

    public function testDetectFormatFallbackIsNotSticky(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'mata_log_parser_');
        self::assertNotFalse($logFile);

        try {
            file_put_contents($logFile, '');
            $fallbackParser = LogParserFactory::detectFormat($logFile);
            $this->assertInstanceOf(PhpErrorLogParser::class, $fallbackParser);

            $jsonLine = '{"timestamp":"2026-01-01T00:00:00Z","level":"error","message":"x"}';
            file_put_contents($logFile, implode("\n", array_fill(0, 10, $jsonLine)) . "\n");

            $jsonParser = LogParserFactory::detectFormat($logFile);
            $this->assertInstanceOf(JsonParser::class, $jsonParser);
        } finally {
            unlink($logFile);
        }
    }

    public function testDetectFormatCachesClassAndReturnsFreshInstances(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';

        // First call detects and caches the parser class.
        $parser1 = LogParserFactory::detectFormat($logFile);

        // Second call should use cached class but return a fresh instance.
        $parser2 = LogParserFactory::detectFormat($logFile);

        $this->assertNotSame($parser1, $parser2);
        $this->assertSame($parser1::class, $parser2::class);
        $this->assertSame($parser1->getName(), $parser2->getName());
    }

    public function testDetectFormatCachesClassesForDifferentFilesAndReturnsFreshInstances(): void
    {
        $phpLog = $this->fixturesPath . '/php_error.log';
        $jsonLog = $this->fixturesPath . '/json.log';

        $parser1 = LogParserFactory::detectFormat($phpLog);
        $parser2 = LogParserFactory::detectFormat($jsonLog);

        // Different files should resolve to their detected parser classes.
        $this->assertNotSame($parser1::class, $parser2::class);
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser1);
        $this->assertInstanceOf(JsonParser::class, $parser2);

        // Second call to same files should use cached classes but return fresh instances.
        $parser1Again = LogParserFactory::detectFormat($phpLog);
        $parser2Again = LogParserFactory::detectFormat($jsonLog);

        $this->assertNotSame($parser1, $parser1Again);
        $this->assertNotSame($parser2, $parser2Again);
        $this->assertSame($parser1::class, $parser1Again::class);
        $this->assertSame($parser2::class, $parser2Again::class);
        $this->assertSame($parser1->getName(), $parser1Again->getName());
        $this->assertSame($parser2->getName(), $parser2Again->getName());
    }

    public function testClearCacheClearsDetectionCache(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';

        // First call
        $parser1 = LogParserFactory::detectFormat($logFile);

        // Clear cache
        LogParserFactory::clearCache();

        // Second call should return different instance
        $parser2 = LogParserFactory::detectFormat($logFile);

        $this->assertNotSame($parser1, $parser2);
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser1);
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser2);
    }

    public function testClearCacheClearsParsersCache(): void
    {
        // Get parsers to populate cache
        $parsers1 = LogParserFactory::getAvailableParsers();

        // Clear cache
        LogParserFactory::clearCache();

        // Get parsers again
        $parsers2 = LogParserFactory::getAvailableParsers();

        // Should have same content but cache was rebuilt
        $this->assertEquals($parsers1, $parsers2);
    }

    public function testDetectFormatHandlesEmptyFile(): void
    {
        $logFile = $this->fixturesPath . '/empty.log';
        $parser = LogParserFactory::detectFormat($logFile);

        // Should fall back to PHP error_log parser
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser);
    }

    public function testAutoDiscoveryFindsAllParsers(): void
    {
        $parsers = LogParserFactory::getAvailableParsers();

        // Should have exactly 3 parsers
        $this->assertCount(3, $parsers);

        // All standard parsers should be present
        $this->assertContains('php_error_log', $parsers);
        $this->assertContains('monolog', $parsers);
        $this->assertContains('json', $parsers);
    }

    public function testGetParserUsesCachedClassesAndReturnsFreshInstances(): void
    {
        $parser1 = LogParserFactory::getParser('php_error_log');
        $parser2 = LogParserFactory::getParser('php_error_log');

        self::assertNotNull($parser1);
        self::assertNotNull($parser2);

        // getParser uses cached parser classes but returns fresh instances.
        $this->assertNotSame($parser1, $parser2);
        $this->assertSame($parser1::class, $parser2::class);
        $this->assertSame($parser1->getName(), $parser2->getName());

        // After clearing cache, should still resolve the same class and name.
        LogParserFactory::clearCache();
        $parser3 = LogParserFactory::getParser('php_error_log');
        self::assertNotNull($parser3);
        $this->assertNotSame($parser1, $parser3);
        $this->assertSame($parser1::class, $parser3::class);
        $this->assertSame($parser1->getName(), $parser3->getName());
    }

    public function testDetectFormatWorksAfterClearCache(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';

        // Detect once
        $parser1 = LogParserFactory::detectFormat($logFile);
        $this->assertEquals('monolog', $parser1->getName());

        // Clear and detect again
        LogParserFactory::clearCache();
        $parser2 = LogParserFactory::detectFormat($logFile);
        $this->assertEquals('monolog', $parser2->getName());
    }

    public function testGetAvailableParsersIsCached(): void
    {
        // First call builds cache
        $start = microtime(true);
        $parsers1 = LogParserFactory::getAvailableParsers();
        $time1 = microtime(true) - $start;

        // Second call uses cache (should be faster, but we just verify it returns same data)
        $start = microtime(true);
        $parsers2 = LogParserFactory::getAvailableParsers();
        $time2 = microtime(true) - $start;

        $this->assertEquals($parsers1, $parsers2);
        // Note: We don't assert on time as it's unreliable in tests
    }

    public function testDetectFormatPrioritizesParserOrder(): void
    {
        // If a log matches multiple formats, first match wins
        // Our test logs are specific enough that this shouldn't happen,
        // but we test that detection is deterministic
        $logFile = $this->fixturesPath . '/php_error.log';

        $parser1 = LogParserFactory::detectFormat($logFile);
        LogParserFactory::clearCache();
        $parser2 = LogParserFactory::detectFormat($logFile);
        LogParserFactory::clearCache();
        $parser3 = LogParserFactory::detectFormat($logFile);

        // Should always return same type
        $this->assertEquals($parser1->getName(), $parser2->getName());
        $this->assertEquals($parser2->getName(), $parser3->getName());
    }

    public function testGetParserIsNotAffectedByDetectionCache(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';

        // Detect format first (populates detection cache)
        $detectedParser = LogParserFactory::detectFormat($logFile);

        // Get parser by name should still work
        $namedParser = LogParserFactory::getParser('monolog');

        $this->assertInstanceOf(PhpErrorLogParser::class, $detectedParser);
        $this->assertInstanceOf(MonologParser::class, $namedParser);
    }

    public function testFactoryDoesNotCrashWithUnreadableFile(): void
    {
        // Create a temporary file and make it unreadable
        $tempFile = $this->fixturesPath . '/temp_unreadable.log';
        touch($tempFile);
        chmod($tempFile, 0000);

        $parser = LogParserFactory::detectFormat($tempFile);

        // Should fall back to PHP error_log parser without crashing
        $this->assertInstanceOf(PhpErrorLogParser::class, $parser);

        // Cleanup
        chmod($tempFile, 0644);
        unlink($tempFile);
    }

    public function testGetParserHandlesCaseInsensitivity(): void
    {
        // Test that parser names are case-sensitive as expected
        $parser1 = LogParserFactory::getParser('php_error_log');
        $parser2 = LogParserFactory::getParser('PHP_ERROR_LOG');

        $this->assertNotNull($parser1);
        $this->assertNull($parser2); // Should be case-sensitive
    }
}
