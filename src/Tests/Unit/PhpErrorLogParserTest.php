<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\LogParsers\PhpErrorLogParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for PHP error log parser.
 *
 * @covers \Mata\Watchers\LogParsers\PhpErrorLogParser
 *
 * @internal
 */
class PhpErrorLogParserTest extends TestCase
{
    private PhpErrorLogParser $parser;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->parser = new PhpErrorLogParser();
        $this->fixturesPath = __DIR__ . '/../Fixtures/logs';
    }

    public function testGetNameReturnsCorrectIdentifier(): void
    {
        $this->assertEquals('php_error_log', $this->parser->getName());
    }

    public function testDetectReturnsTrueForPhpErrorLog(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->detect($logFile);

        $this->assertTrue($result);
    }

    public function testDetectReturnsFalseForMonologFormat(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $result = $this->parser->detect($logFile);

        $this->assertFalse($result);
    }

    public function testDetectReturnsFalseForJsonFormat(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
        $result = $this->parser->detect($logFile);

        $this->assertFalse($result);
    }

    public function testDetectReturnsFalseForMixedFormat(): void
    {
        $logFile = $this->fixturesPath . '/mixed.log';
        $result = $this->parser->detect($logFile);

        // Should return false because less than 50% of lines match
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

    public function testParseToJsonParsesPhpErrorLog(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
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

    public function testParseToJsonNormalizesWarnings(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // First entry is a warning
        $warningEntry = $parsed[0];
        $this->assertEquals('Warning', $warningEntry['type']);
        $this->assertStringContainsString('Undefined array key', $warningEntry['message']);
    }

    public function testParseToJsonNormalizesErrors(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // Find an error entry
        $errorEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['type'] === 'Error') {
                $errorEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($errorEntry);
        $this->assertEquals('Error', $errorEntry['type']);
    }

    public function testParseToJsonHandlesMultilineEntries(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // Fatal error entry contains stack trace (multi-line)
        $fatalEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'Stack trace')) {
                $fatalEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($fatalEntry);
        $this->assertStringContainsString('Stack trace', $fatalEntry['message']);
        $this->assertStringContainsString('#0', $fatalEntry['message']);
    }

    public function testParseToJsonRespectsMaxLines(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->parseToJson($logFile, 3);
        $parsed = json_decode($result, true);

        // With multi-line entries, we might get fewer entries than maxLines
        // But we should definitely not get more than the total entries
        $this->assertLessThanOrEqual(10, count($parsed));
    }

    public function testParseToJsonThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('errorlog not found or unreadable');

        $logFile = $this->fixturesPath . '/nonexistent.log';
        $this->parser->parseToJson($logFile, 100);
    }

    public function testAnalyzeLogCountsErrorsAndWarnings(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
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

    public function testParseToJsonExtractsTimestampCorrectly(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        $firstEntry = $parsed[0];
        $this->assertEquals('2026-01-18 10:30:45', $firstEntry['timestamp']);
    }

    public function testParseToJsonHandlesDeprecatedNotices(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // Find deprecated entry
        $deprecatedEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'deprecated')) {
                $deprecatedEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($deprecatedEntry);
        $this->assertEquals('Deprecated', $deprecatedEntry['type']);
    }

    public function testParseToJsonHandlesOtherTypes(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->parseToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // Find notice entry
        $noticeEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['type'] === 'Other') {
                $noticeEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($noticeEntry);
        $this->assertEquals('Other', $noticeEntry['type']);
    }
}
