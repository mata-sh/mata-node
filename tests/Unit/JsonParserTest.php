<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\LogParsers\JsonParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JSON log parser.
 *
 * @covers \Mata\Watchers\LogParsers\JsonParser
 *
 * @internal
 */
class JsonParserTest extends TestCase
{
    private JsonParser $parser;
    private string $fixturesPath;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->parser = new JsonParser();
        $this->fixturesPath = __DIR__ . '/../Fixtures/logs';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        $this->tempFiles = [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $logFile): array
    {
        return $this->parser->parseLines(file($logFile, FILE_IGNORE_NEW_LINES) ?: []);
    }

    private function createTempLogFile(string $contents): string
    {
        $tempLog = tempnam(sys_get_temp_dir(), 'mata-json-');
        $this->assertNotFalse($tempLog);

        file_put_contents($tempLog, $contents);
        $this->tempFiles[] = $tempLog;

        return $tempLog;
    }

    public function testGetNameReturnsCorrectIdentifier(): void
    {
        $this->assertEquals('json', $this->parser->getName());
    }

    public function testDetectReturnsTrueForJsonFormat(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->detect($logFile);

        $this->assertTrue($result);
    }

    public function testDetectReturnsFalseForPhpErrorLog(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->detect($logFile);

        $this->assertFalse($result);
    }

    public function testDetectReturnsFalseForMonologFormat(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $result = $this->parser->detect($logFile);

        $this->assertFalse($result);
    }

    public function testDetectReturnsFalseForNonExistentFile(): void
    {
        $logFile = $this->fixturesPath . '/nonexistent.log';
        $result = $this->parser->detect($logFile);

        $this->assertFalse($result);
    }

    public function testDetectReturnsFalseForEmptyFile(): void
    {
        $logFile = $this->fixturesPath . '/empty.log';
        $result = $this->parser->detect($logFile);

        $this->assertFalse($result);
    }

    public function testDetectLooksOnlyAtTheLeadingNonBlankLines(): void
    {
        $record = '{"level":"info","message":"ok"}';
        $logFile = $this->createTempLogFile("\n" . str_repeat($record . "\n\n", 10) . str_repeat("plain text\n", 1000));

        $this->assertTrue($this->parser->detect($logFile));
    }

    public function testDetectCutsAnOverlongLine(): void
    {
        $logFile = $this->createTempLogFile('{"message":"' . str_repeat('x', 6 * 1024 * 1024) . "\"}\n");

        $this->assertFalse($this->parser->detect($logFile));
    }

    public function testParseLinesParsesJsonFormat(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);

        // Check first entry structure
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('timestamp', $firstEntry);
        $this->assertArrayHasKey('type', $firstEntry);
        $this->assertArrayHasKey('message', $firstEntry);
    }

    public function testParseLinesExtractsTimestampCorrectly(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        $firstEntry = $parsed[0];
        $this->assertEquals('2026-01-18 10:30:45', $firstEntry['timestamp']);
    }

    public function testParseLinesNormalizesErrorLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        // Find error entry
        $errorEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['type'] === 'Error' && str_contains($entry['message'], 'Redis')) {
                $errorEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($errorEntry);
        $this->assertEquals('Error', $errorEntry['type']);
    }

    public function testParseLinesNormalizesWarningLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        // Find warning entry
        $warningEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['type'] === 'Warning') {
                $warningEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($warningEntry);
        $this->assertEquals('Warning', $warningEntry['type']);
    }

    public function testParseLinesNormalizesCriticalLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        // Find critical entry
        $criticalEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'Rate limit exceeded')) {
                $criticalEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($criticalEntry);
        $this->assertEquals('Error', $criticalEntry['type']);
    }

    public function testParseLinesNormalizesEmergencyLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        // Find emergency entry
        $emergencyEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'replication lag')) {
                $emergencyEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($emergencyEntry);
        $this->assertEquals('Error', $emergencyEntry['type']);
    }

    public function testParseLinesNormalizesInfoLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        // First entry is info
        $infoEntry = $parsed[0];
        $this->assertEquals('Other', $infoEntry['type']);
    }

    public function testParseLinesNormalizesDebugLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        // Find debug entry
        $debugEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'SQL query')) {
                $debugEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($debugEntry);
        $this->assertEquals('Other', $debugEntry['type']);
    }

    public function testParseLinesIncludesContextData(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $parsed = $this->parse($logFile);

        // First entry has context
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('context', $firstEntry);
        $this->assertIsArray($firstEntry['context']);
        $this->assertArrayHasKey('version', $firstEntry['context']);
        $this->assertEquals('2.1.0', $firstEntry['context']['version']);
    }

    public function testParseLinesHandlesExtraField(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","level":"info","message":"Test","context":{"foo":"bar"},"extra":{"baz":"qux"}}');

        $parsed = $this->parse($tempLog);

        $this->assertArrayHasKey('extra', $parsed[0]);
        $this->assertEquals(['baz' => 'qux'], $parsed[0]['extra']);
    }

    public function testParseLinesSkipsInvalidLines(): void
    {
        $tempLog = $this->createTempLogFile("not valid json\n{\"timestamp\":\"2026-01-18T10:30:45.123Z\",\"level\":\"info\",\"message\":\"Valid\"}\ninvalid again");

        $parsed = $this->parse($tempLog);

        // Should only have the valid entry
        $this->assertCount(1, $parsed);
        $this->assertEquals('Valid', $parsed[0]['message']);
    }

    public function testParseLinesHandlesAlternativeFieldNames(): void
    {
        $tempLog = $this->createTempLogFile('{"time":"2026-01-18T10:30:45.123Z","severity":"error","msg":"Alternative fields"}');

        $parsed = $this->parse($tempLog);

        $this->assertEquals('2026-01-18 10:30:45', $parsed[0]['timestamp']);
        $this->assertEquals('Alternative fields', $parsed[0]['message']);
    }

    public function testParseLinesReadsMonologJsonFormatterRecords(): void
    {
        $tempLog = $this->createTempLogFile('{"message":"boom","context":{"id":7},"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-01-18T10:30:45.123456+00:00","extra":{}}');

        $parsed = $this->parse($tempLog);

        $this->assertSame('2026-01-18 10:30:45', $parsed[0]['timestamp']);
        $this->assertSame('Error', $parsed[0]['type']);
        $this->assertSame(['id' => 7], $parsed[0]['context']);
    }

    public function testAnalyzeLogCountsErrorsAndWarnings(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertSame('json', $result->parserName);
        $this->assertSame(['fatal' => 4, 'warnings' => 2, 'total' => 10], $result->counts());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $result->fatalDigest);
    }

    public function testAnalyzeLogReturnsZerosForNonExistentFile(): void
    {
        $logFile = $this->fixturesPath . '/nonexistent.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertSame(['fatal' => 0, 'warnings' => 0, 'total' => 0], $result->counts());
        $this->assertNull($result->fatalDigest);
    }

    public function testAnalyzeLogReturnsZerosForEmptyFile(): void
    {
        $logFile = $this->fixturesPath . '/empty.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertSame(['fatal' => 0, 'warnings' => 0, 'total' => 0], $result->counts());
        $this->assertNull($result->fatalDigest);
    }

    public function testParseLinesHandlesEmptyContext(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","level":"info","message":"No context"}');

        $parsed = $this->parse($tempLog);

        $this->assertArrayNotHasKey('context', $parsed[0]);
    }

    public function testParseLinesHandlesMissingTimestamp(): void
    {
        $tempLog = $this->createTempLogFile('{"level":"info","message":"No timestamp"}');

        $parsed = $this->parse($tempLog);

        // Should have empty timestamp
        $this->assertEquals('', $parsed[0]['timestamp']);
    }

    public function testParseLinesHandlesMissingLevel(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","message":"No level"}');

        $parsed = $this->parse($tempLog);

        // Should default to info level
        $this->assertEquals('Other', $parsed[0]['type']);
    }

    public function testParseLinesHandlesMissingMessage(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","level":"info"}');

        $parsed = $this->parse($tempLog);

        // Should have empty message
        $this->assertEquals('', $parsed[0]['message']);
    }
}
