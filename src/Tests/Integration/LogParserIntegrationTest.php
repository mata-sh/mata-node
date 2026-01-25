<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\LogParsers\LogParserFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
    private ?string $previousErrorLog = null;

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
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
            $this->previousErrorLog = null;
        }
        LogParserFactory::clearCache();
    }

    private function createAppWatcher(array $watcherConfig): AppWatcher
    {
        $transmitConfig = [];

        return new AppWatcher($this->tools, new NullLogger(), $this->rootPath, $watcherConfig, $transmitConfig);
    }

    public function testGetLogEntriesWithPhpErrorLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertNotEmpty($parsed['logs/php_error.log']);

        $firstEntry = $parsed['logs/php_error.log'][0] ?? '';
        $this->assertMatchesRegularExpression('/^\[\d{2}-[A-Za-z]{3}-\d{4}/', $firstEntry);
        $this->assertStringContainsString('PHP', $firstEntry);
    }

    public function testGetLogEntriesWithMonologLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/monolog.log', $parsed);
        $this->assertNotEmpty($parsed['logs/monolog.log']);

        $firstEntry = $parsed['logs/monolog.log'][0] ?? '';
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \w+\.\w+:/', $firstEntry);
    }

    public function testGetLogEntriesWithJsonLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/json.log', $parsed);
        $this->assertNotEmpty($parsed['logs/json.log']);

        $firstEntry = $parsed['logs/json.log'][0] ?? '';
        $decoded = json_decode($firstEntry, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('context', $decoded);
    }

    public function testGetLogEntriesWithConfiguredFormat(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => [
                    [
                        'type' => 'monolog',
                        'paths' => ['logs/php_error.log'],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertEmpty($parsed['logs/php_error.log']);
    }

    public function testGetLogEntriesWithAutoDetection(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
    }

    public function testGetLogEntriesWithMonologFormat(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);

        // Should filter for errors using Monolog pattern
        foreach ($parsed['logs/monolog.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/ERROR:|CRITICAL:|EMERGENCY:|ALERT:/', $line);
        }
    }

    public function testGetLogEntriesWithJsonFormat(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'warnings', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);

        // Should filter for warnings using JSON pattern
        foreach ($parsed['logs/json.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringContainsString('"level":"warning"', $line);
        }
    }

    public function testGetLogStatsWithPhpErrorLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $resultJson = $appWatcher->getAppLogStatus('testapp');
        $this->assertIsArray($resultJson);
        $result = $resultJson;

        $this->assertIsArray($result);
        $this->assertArrayHasKey('fatal', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThan(0, $result['fatal']);
        $this->assertGreaterThan(0, $result['warnings']);
    }

    public function testGetLogStatsWithMonologLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $resultJson = $appWatcher->getAppLogStatus('testapp');
        $this->assertIsArray($resultJson);
        $result = $resultJson;

        $this->assertIsArray($result);
        $this->assertArrayHasKey('fatal', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('total', $result);
    }

    public function testGetLogStatsWithJsonLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $resultJson = $appWatcher->getAppLogStatus('testapp');
        $this->assertIsArray($resultJson);
        $result = $resultJson;

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

        // Multiple calls should use cached parser
        $result1 = $appWatcher->getLogEntries('testapp', 'errors', 50);
        $result2 = $appWatcher->getLogEntries('testapp', 'errors', 50);
        $result3 = $appWatcher->getLogEntries('testapp', 'errors', 50);

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

        $phpResult = $appWatcher->getLogEntries('phpapp', 'errors', 10);
        $monologResult = $appWatcher->getLogEntries('monologapp', 'errors', 10);
        $jsonResult = $appWatcher->getLogEntries('jsonapp', 'errors', 10);

        $this->assertIsArray($phpResult);
        $this->assertIsArray($monologResult);
        $this->assertIsArray($jsonResult);

        $phpParsed = $phpResult;
        $monologParsed = $monologResult;
        $jsonParsed = $jsonResult;

        $this->assertArrayHasKey('logs/php_error.log', $phpParsed);
        $this->assertArrayHasKey('logs/monolog.log', $monologParsed);
        $this->assertArrayHasKey('logs/json.log', $jsonParsed);

        foreach ($phpParsed['logs/php_error.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/PHP (Fatal error|Parse error|Error)/', $line);
        }

        foreach ($monologParsed['logs/monolog.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/\.ERROR:|\.CRITICAL:|\.EMERGENCY:|\.ALERT:/', $line);
        }

        foreach ($jsonParsed['logs/json.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/\"level\":\"(error|critical|emergency|alert)\"/', $line);
        }
    }

    public function testInvalidLogTypeConfigFallsBackToAutoDetect(): void
    {
        $this->redirectErrorLog();
        $watcherConfig = [
            'testapp' => [
                'logs' => [
                    [
                        'type' => 'invalid_format',
                        'paths' => ['logs/php_error.log'],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        // Should fall back to default parser
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertNotEmpty($parsed['logs/php_error.log']);
    }

    public function testGetLogEntriesWithNoFilter(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertNotEmpty($parsed['logs/php_error.log']);
    }

    public function testGetLogEntriesRespectsLimit(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 3);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertLessThanOrEqual(3, count($parsed['logs/php_error.log']));
    }

    public function testGetLogEntriesIncludesStackTraceLines(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', '', 50);
        $parsed = $result;

        $stackTraceFound = false;
        $stackFrameFound = false;
        foreach ($parsed['logs/php_error.log'] as $line) {
            if (str_contains($line, 'Stack trace')) {
                $stackTraceFound = true;
            }
            if (str_contains($line, '#0')) {
                $stackFrameFound = true;
            }
        }

        $this->assertTrue($stackTraceFound);
        $this->assertTrue($stackFrameFound);
    }

    public function testFormatOverrideInConfig(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => [
                    [
                        'type' => 'monolog',
                        'paths' => ['logs/monolog.log'],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getLogEntries('testapp', 'errors', 50);

        $this->assertIsArray($result);
        $parsed = $result;

        // Should use Monolog grep pattern
        foreach ($parsed['logs/monolog.log'] as $line) {
            if ($line !== '') {
                $this->assertMatchesRegularExpression('/ERROR:|CRITICAL:|EMERGENCY:|ALERT:/', $line);
            }
        }
    }

    private function redirectErrorLog(): void
    {
        if ($this->previousErrorLog !== null) {
            return;
        }

        $this->previousErrorLog = ini_get('error_log') ?: '';
        $tempPath = sys_get_temp_dir() . '/mata-node-test-error.log';
        ini_set('error_log', $tempPath);
        ini_set('log_errors', '1');
    }
}
