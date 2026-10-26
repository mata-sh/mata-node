<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Watchers\LogParsers\MonologParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Monolog parser.
 *
 * @covers \Mata\Watchers\LogParsers\MonologParser
 *
 * @internal
 */
class MonologParserTest extends TestCase
{
    private MonologParser $parser;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->parser = new MonologParser();
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
        $this->assertEquals('monolog', $this->parser->getName());
    }

    public function testDetectReturnsTrueForMonologFormat(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $result = $this->parser->detect($logFile);

        $this->assertTrue($result);
    }

    public function testDetectReturnsFalseForPhpErrorLog(): void
    {
        $logFile = $this->fixturesPath . '/php_error.log';
        $result = $this->parser->detect($logFile);

        $this->assertFalse($result);
    }

    public function testDetectReturnsFalseForJsonFormat(): void
    {
        $logFile = $this->fixturesPath . '/json.log';
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

    public function testParseLinesParsesMonologFormat(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);

        // Check first entry structure
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('timestamp', $firstEntry);
        $this->assertArrayHasKey('type', $firstEntry);
        $this->assertArrayHasKey('message', $firstEntry);
        $this->assertArrayHasKey('channel', $firstEntry);
        $this->assertArrayHasKey('level', $firstEntry);
    }

    public function testParseLinesExtractsChannelCorrectly(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        $firstEntry = $parsed[0];
        $this->assertEquals('app', $firstEntry['channel']);
    }

    public function testParseLinesExtractsLevelCorrectly(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        $firstEntry = $parsed[0];
        $this->assertEquals('INFO', $firstEntry['level']);
    }

    public function testParseLinesNormalizesErrorLevel(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // Find ERROR entry
        $errorEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['level'] === 'ERROR') {
                $errorEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($errorEntry);
        $this->assertEquals('Error', $errorEntry['type']);
    }

    public function testParseLinesNormalizesWarningLevel(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // Find WARNING entry
        $warningEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['level'] === 'WARNING') {
                $warningEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($warningEntry);
        $this->assertEquals('Warning', $warningEntry['type']);
    }

    public function testParseLinesNormalizesCriticalLevel(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // Find CRITICAL entry
        $criticalEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['level'] === 'CRITICAL') {
                $criticalEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($criticalEntry);
        $this->assertEquals('Error', $criticalEntry['type']);
    }

    public function testParseLinesNormalizesEmergencyLevel(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // Find EMERGENCY entry
        $emergencyEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['level'] === 'EMERGENCY') {
                $emergencyEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($emergencyEntry);
        $this->assertEquals('Error', $emergencyEntry['type']);
    }

    public function testParseLinesNormalizesInfoLevel(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // Find INFO entry
        $infoEntry = $parsed[0];
        $this->assertEquals('INFO', $infoEntry['level']);
        $this->assertEquals('Other', $infoEntry['type']);
    }

    public function testParseLinesHandlesMultilineEntries(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // API ERROR entry contains stack trace (multi-line)
        $stackTraceEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'Stack trace')) {
                $stackTraceEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($stackTraceEntry);
        $this->assertStringContainsString('Stack trace', $stackTraceEntry['message']);
        $this->assertStringContainsString('GuzzleHttp', $stackTraceEntry['message']);
    }

    public function testParseLinesExtractsTimestampCorrectly(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        $firstEntry = $parsed[0];
        $this->assertEquals('2026-01-18 10:30:45', $firstEntry['timestamp']);
    }

    public function testParseLinesHandlesContextData(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // First entry contains context data
        $firstEntry = $parsed[0];
        $this->assertStringContainsString('user_id', $firstEntry['message']);
    }

    public function testAnalyzeLogCountsErrorsAndWarnings(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $result = $this->parser->analyzeLog($logFile);

        $this->assertSame('monolog', $result->parserName);
        $this->assertSame(['fatal' => 6, 'warnings' => 2, 'total' => 16], $result->counts());
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

    public function testParseLinesHandlesDifferentChannels(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        $channels = array_unique(array_column($parsed, 'channel'));

        // Should have multiple channels (app, security, payment, worker, api)
        $this->assertGreaterThan(1, count($channels));
        $this->assertContains('app', $channels);
        $this->assertContains('security', $channels);
    }

    public function testParseLinesHandlesAlertLevel(): void
    {
        $logFile = $this->fixturesPath . '/monolog.log';
        $parsed = $this->parse($logFile);

        // Find ALERT entry
        $alertEntry = null;
        foreach ($parsed as $entry) {
            if ($entry['level'] === 'ALERT') {
                $alertEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($alertEntry);
        $this->assertEquals('Error', $alertEntry['type']);
    }
}
