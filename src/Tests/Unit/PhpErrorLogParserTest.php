<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\LogParsers\PhpErrorLogParser;
use PHPUnit\Framework\TestCase;

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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $logFile): array
    {
        return $this->parser->parseLines(file($logFile, FILE_IGNORE_NEW_LINES) ?: []);
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

    public function testParseLinesParsesPhpErrorLog(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parsed = $this->parse($logFile);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);

        // Check first entry structure
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('timestamp', $firstEntry);
        $this->assertArrayHasKey('type', $firstEntry);
        $this->assertArrayHasKey('message', $firstEntry);
    }

    public function testParseLinesNormalizesWarnings(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parsed = $this->parse($logFile);

        // First entry is a warning
        $warningEntry = $parsed[0];
        $this->assertEquals('Warning', $warningEntry['type']);
        $this->assertStringContainsString('Undefined array key', $warningEntry['message']);
    }

    public function testParseLinesNormalizesErrors(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parsed = $this->parse($logFile);

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

    public function testParseLinesHandlesMultilineEntries(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parsed = $this->parse($logFile);

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

    public function testAnalyzeLogCountsErrorsAndWarnings(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertSame('php_error_log', $result->parserName);
        $this->assertSame(['fatal' => 4, 'warnings' => 5, 'total' => 16], $result->counts());
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

    public function testParseLinesExtractsTimestampCorrectly(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parsed = $this->parse($logFile);

        $firstEntry = $parsed[0];
        $this->assertEquals('2026-01-18 10:30:45', $firstEntry['timestamp']);
    }

    public function testParseLinesHandlesDeprecatedNotices(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parsed = $this->parse($logFile);

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

    public function testParseLinesNormalizesNoticesAsWarnings(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $parsed = $this->parse($logFile);

        $noticeEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'Undefined variable: config')) {
                $noticeEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($noticeEntry);
        $this->assertSame('Warning', $noticeEntry['type']);
    }

    public function testAnalyzeLogCountsNoticesAsWarnings(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';

        $result = $this->parser->analyzeLog($logFile);

        $this->assertSame(5, $result->warnings);
    }

    public function testAnalyzeLogDoesNotTreatAnErrorInWarningMessageAsAnError(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'mata_php_error_log_');
        self::assertNotFalse($logFile);

        file_put_contents(
            $logFile,
            "[19-Jul-2026 12:00:00 UTC] PHP Warning: PHP Error text in a warning message\n"
        );

        try {
            $result = $this->parser->analyzeLog($logFile);

            $this->assertSame(['fatal' => 0, 'warnings' => 1, 'total' => 1], $result->counts());
            $this->assertNull($result->fatalDigest);
        } finally {
            unlink($logFile);
        }
    }
}
