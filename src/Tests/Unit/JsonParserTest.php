<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Services\Command\ShellCommands;
use Mata\Tests\Support\FakeCommandRunner;
use Mata\Watchers\LogParsers\JsonParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testDetectUsesInjectedCommandRunner(): void
    {
        $logFile = $this->createTempLogFile("not used by fake runner\n");
        $parser = new JsonParser(new FakeCommandRunner([
            ShellCommands::head($logFile, 10) => "{\"level\":\"error\",\"message\":\"failed\"}\n",
        ]));

        $this->assertTrue($parser->detect($logFile));
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

    public function testParseToJsonParsesJsonFormat(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);

        $this->assertJson($result);

        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);

        // Check first entry structure
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('timestamp', $firstEntry);
        $this->assertArrayHasKey('type', $firstEntry);
        $this->assertArrayHasKey('message', $firstEntry);
    }

    public function testParseToJsonExtractsTimestampCorrectly(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        $firstEntry = $parsed[0];
        $this->assertEquals('2026-01-18 10:30:45', $firstEntry['timestamp']);
    }

    public function testParseToJsonNormalizesErrorLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

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

    public function testParseToJsonNormalizesWarningLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

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

    public function testParseToJsonNormalizesCriticalLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

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

    public function testParseToJsonNormalizesEmergencyLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

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

    public function testParseToJsonNormalizesInfoLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // First entry is info
        $infoEntry = $parsed[0];
        $this->assertEquals('Other', $infoEntry['type']);
    }

    public function testParseToJsonNormalizesDebugLevel(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

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

    public function testParseToJsonIncludesContextData(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // First entry has context
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('context', $firstEntry);
        $this->assertIsArray($firstEntry['context']);
        $this->assertArrayHasKey('version', $firstEntry['context']);
        $this->assertEquals('2.1.0', $firstEntry['context']['version']);
    }

    public function testParseToJsonHandlesExtraField(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","level":"info","message":"Test","context":{"foo":"bar"},"extra":{"baz":"qux"}}');

        $result = $this->parser->parseToJson($tempLog, 100);
        $parsed = json_decode($result, true);

        $this->assertArrayHasKey('extra', $parsed[0]);
        $this->assertEquals(['baz' => 'qux'], $parsed[0]['extra']);
    }

    public function testParseToJsonSkipsInvalidLines(): void
    {
        $tempLog = $this->createTempLogFile("not valid json\n{\"timestamp\":\"2026-01-18T10:30:45.123Z\",\"level\":\"info\",\"message\":\"Valid\"}\ninvalid again");

        $result = $this->parser->parseToJson($tempLog, 100);
        $parsed = json_decode($result, true);

        // Should only have the valid entry
        $this->assertCount(1, $parsed);
        $this->assertEquals('Valid', $parsed[0]['message']);
    }

    public function testParseToJsonHandlesAlternativeFieldNames(): void
    {
        $tempLog = $this->createTempLogFile('{"time":"2026-01-18T10:30:45.123Z","severity":"error","msg":"Alternative fields"}');

        $result = $this->parser->parseToJson($tempLog, 100);
        $parsed = json_decode($result, true);

        $this->assertEquals('2026-01-18 10:30:45', $parsed[0]['timestamp']);
        $this->assertEquals('Alternative fields', $parsed[0]['message']);
    }

    public function testParseToJsonThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('log file not found or unreadable');

        $logFile = $this->fixturesPath . '/nonexistent.log';
        $this->parser->parseToJson($logFile, 100);
    }

    public function testParseToJsonRespectsMaxLines(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->parseToJson($logFile, 5);
        $parsed = json_decode($result, true);

        // Should have at most 5 entries
        $this->assertLessThanOrEqual(5, count($parsed));
    }

    public function testAnalyzeLogCountsErrorsAndWarnings(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('fatal', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('total', $result);

        // Check that counts are reasonable
        $this->assertGreaterThan(0, $result['fatal']);
        $this->assertGreaterThan(0, $result['warnings']);
        $this->assertGreaterThan(0, $result['total']);
    }

    public function testAnalyzeLogReturnsZerosForNonExistentFile(): void
    {
        $logFile = $this->fixturesPath . '/nonexistent.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertEquals(['fatal' => 0, 'warnings' => 0, 'total' => 0], $result);
    }

    public function testAnalyzeLogReturnsZerosForEmptyFile(): void
    {
        $logFile = $this->fixturesPath . '/empty.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertEquals(['fatal' => 0, 'warnings' => 0, 'total' => 0], $result);
    }

    public function testParseToJsonHandlesEmptyContext(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","level":"info","message":"No context"}');

        $result = $this->parser->parseToJson($tempLog, 100);
        $parsed = json_decode($result, true);

        $this->assertArrayNotHasKey('context', $parsed[0]);
    }

    public function testParseToJsonHandlesMissingTimestamp(): void
    {
        $tempLog = $this->createTempLogFile('{"level":"info","message":"No timestamp"}');

        $result = $this->parser->parseToJson($tempLog, 100);
        $parsed = json_decode($result, true);

        // Should have empty timestamp
        $this->assertEquals('', $parsed[0]['timestamp']);
    }

    public function testParseToJsonHandlesMissingLevel(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","message":"No level"}');

        $result = $this->parser->parseToJson($tempLog, 100);
        $parsed = json_decode($result, true);

        // Should default to info level
        $this->assertEquals('Other', $parsed[0]['type']);
    }

    public function testParseToJsonHandlesMissingMessage(): void
    {
        $tempLog = $this->createTempLogFile('{"timestamp":"2026-01-18T10:30:45.123Z","level":"info"}');

        $result = $this->parser->parseToJson($tempLog, 100);
        $parsed = json_decode($result, true);

        // Should have empty message
        $this->assertEquals('', $parsed[0]['message']);
    }
}
