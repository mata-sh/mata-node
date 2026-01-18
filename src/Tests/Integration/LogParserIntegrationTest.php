<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\LogParsers\LogParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for log parsing system with AppWatcher.
 *
 * @covers \Mata\Watchers\AppWatcher
 * @covers \Mata\Watchers\LogParsers\JsonParser
 * @covers \Mata\Watchers\LogParsers\LogParserFactory
 * @covers \Mata\Watchers\LogParsers\MonologParser
 * @covers \Mata\Watchers\LogParsers\PhpErrorLogParser
 *
 * @internal
 */
class LogParserIntegrationTest extends TestCase
{
    private AppWatcher $appWatcher;
    private string $fixturesPath;
    private string $rootPath;
    private Tools $tools;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../Fixtures/logs';
        $this->rootPath = __DIR__ . '/../Fixtures';
        $this->tools = new Tools();

        // Clear parser factory caches
        LogParserFactory::clearCache();
    }

    protected function tearDown(): void
    {
        LogParserFactory::clearCache();
    }

    private function createAppWatcher(array $watcherConfig): AppWatcher
    {
        $transmitConfig = [];

        return new AppWatcher($this->tools, $this->rootPath, $watcherConfig, $transmitConfig);
    }

    public function testErrorlogToJsonWithPhpErrorLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/php_error.log';

        $result = $appWatcher->errorlogToJson($logFile, 100);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);

        // Verify structure
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('timestamp', $firstEntry);
        $this->assertArrayHasKey('type', $firstEntry);
        $this->assertArrayHasKey('message', $firstEntry);
    }

    public function testErrorlogToJsonWithMonologLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/monolog.log';

        $result = $appWatcher->errorlogToJson($logFile, 100);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);

        // Verify Monolog-specific structure
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('channel', $firstEntry);
        $this->assertArrayHasKey('level', $firstEntry);
        $this->assertEquals('app', $firstEntry['channel']);
    }

    public function testErrorlogToJsonWithJsonLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/json.log';

        $result = $appWatcher->errorlogToJson($logFile, 100);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);

        // Verify JSON-specific structure
        $firstEntry = $parsed[0];
        $this->assertArrayHasKey('context', $firstEntry);
        $this->assertIsArray($firstEntry['context']);
    }

    public function testErrorlogToJsonWithConfiguredFormat(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
                'log_format' => 'monolog', // Force monolog parser
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/php_error.log';

        $result = $appWatcher->errorlogToJson($logFile, 100);

        $this->assertJson($result);
        // Parser will try to parse PHP error log as Monolog, might produce empty or different results
        // This tests that config override is respected
    }

    public function testGetLogsWithAutoDetection(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
    }

    public function testGetLogsWithMonologFormat(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);

        // Should filter for errors using Monolog pattern
        foreach ($parsed as $line) {
            $this->assertMatchesRegularExpression('/ERROR:|CRITICAL:|EMERGENCY:|ALERT:/', $line);
        }
    }

    public function testGetLogsWithJsonFormat(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'warnings', 50);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);

        // Should filter for warnings using JSON pattern
        foreach ($parsed as $line) {
            $this->assertStringContainsString('"level":"warning"', $line);
        }
    }

    public function testAnalyzeErrorLogWithPhpErrorLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/php_error.log';

        $result = $appWatcher->getLogStats($logFile);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('fatal', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThan(0, $result['fatal']);
        $this->assertGreaterThan(0, $result['warnings']);
    }

    public function testAnalyzeErrorLogWithMonologLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/monolog.log';

        $result = $appWatcher->getLogStats($logFile);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('fatal', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('total', $result);
    }

    public function testAnalyzeErrorLogWithJsonLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/json.log';

        $result = $appWatcher->getLogStats($logFile);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('fatal', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('total', $result);
    }

    public function testParserCachingAcrossMultipleCalls(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/php_error.log';

        // Multiple calls should use cached parser
        $result1 = $appWatcher->errorlogToJson($logFile, 100);
        $result2 = $appWatcher->errorlogToJson($logFile, 100);
        $result3 = $appWatcher->errorlogToJson($logFile, 100);

        // Results should be consistent
        $this->assertEquals($result1, $result2);
        $this->assertEquals($result2, $result3);
    }

    public function testMultipleAppsWithDifferentFormats(): void
    {
        $watcherConfig = [
            'phpapp' => [
                'logs' => ['logs/php_error.log'],
            ],
            'monologapp' => [
                'logs' => ['logs/monolog.log'],
            ],
            'jsonapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        // Test each app uses correct parser
        $phpResult = $appWatcher->errorlogToJson($this->fixturesPath . '/php_error.log', 10);
        $monologResult = $appWatcher->errorlogToJson($this->fixturesPath . '/monolog.log', 10);
        $jsonResult = $appWatcher->errorlogToJson($this->fixturesPath . '/json.log', 10);

        $this->assertJson($phpResult);
        $this->assertJson($monologResult);
        $this->assertJson($jsonResult);

        // Verify each has correct structure
        $phpParsed = json_decode($phpResult, true);
        $monologParsed = json_decode($monologResult, true);
        $jsonParsed = json_decode($jsonResult, true);

        // PHP log shouldn't have channel
        $this->assertArrayNotHasKey('channel', $phpParsed[0]);

        // Monolog should have channel
        $this->assertArrayHasKey('channel', $monologParsed[0]);

        // JSON should have context
        $this->assertArrayHasKey('context', $jsonParsed[0]);
    }

    public function testInvalidLogFormatConfigFallsBackToAutoDetect(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
                'log_format' => 'invalid_format', // Invalid format
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/php_error.log';

        // Should fall back to auto-detection
        $result = $appWatcher->errorlogToJson($logFile, 100);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);
    }

    public function testGetLogsWithNoFilter(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 50);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
        $this->assertNotEmpty($parsed);
    }

    public function testGetLogsRespectsLimit(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 3);

        $this->assertJson($result);
        $parsed = json_decode($result, true);
        $this->assertIsArray($parsed);
        $this->assertLessThanOrEqual(3, count($parsed));
    }

    public function testErrorlogToJsonHandlesMultilineEntries(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $logFile = $this->fixturesPath . '/php_error.log';

        $result = $appWatcher->errorlogToJson($logFile, 100);
        $parsed = json_decode($result, true);

        // Find entry with stack trace
        $stackTraceEntry = null;
        foreach ($parsed as $entry) {
            if (str_contains($entry['message'], 'Stack trace')) {
                $stackTraceEntry = $entry;

                break;
            }
        }

        $this->assertNotNull($stackTraceEntry);
        $this->assertStringContainsString('#0', $stackTraceEntry['message']);
    }

    public function testFormatOverrideInConfig(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
                'log_format' => 'monolog', // Explicit format
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertJson($result);
        $parsed = json_decode($result, true);

        // Should use Monolog grep pattern
        foreach ($parsed as $line) {
            if (!empty($line)) {
                $this->assertMatchesRegularExpression('/ERROR:|CRITICAL:|EMERGENCY:|ALERT:/', $line);
            }
        }
    }
}
