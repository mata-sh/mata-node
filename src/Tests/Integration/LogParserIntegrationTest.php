<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\LogParsers\LogParserFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;

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

    private function createAppWatcher(array $watcherConfig, ?LoggerInterface $logger = null, array $transmitConfig = []): AppWatcher
    {
        return new AppWatcher($this->tools, $logger ?? new NullLogger(), $this->rootPath, $watcherConfig, $transmitConfig);
    }

    public function testGetLogEntriesWithPhpErrorLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getAppLogEntries('testapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertNotEmpty($parsed['logs/php_error.log']);

        $firstEntry = $parsed['logs/php_error.log'][0] ?? [];
        $this->assertParsedLogEntry($firstEntry);
        $this->assertContains($firstEntry['type'], ['Error', 'Warning', 'Deprecated', 'Other']);
    }

    public function testGetLogEntriesWithMonologLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/monolog.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getAppLogEntries('testapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/monolog.log', $parsed);
        $this->assertNotEmpty($parsed['logs/monolog.log']);

        $firstEntry = $parsed['logs/monolog.log'][0] ?? [];
        $this->assertParsedLogEntry($firstEntry);
        $this->assertArrayHasKey('level', $firstEntry);
    }

    public function testGetLogEntriesWithJsonLog(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/json.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getAppLogEntries('testapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/json.log', $parsed);
        $this->assertNotEmpty($parsed['logs/json.log']);

        $firstEntry = $parsed['logs/json.log'][0] ?? [];
        $this->assertParsedLogEntry($firstEntry);
        $this->assertArrayHasKey('context', $firstEntry);
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
        $result = $appWatcher->getAppLogEntries('testapp', 'errors', 50);

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
        $result = $appWatcher->getAppLogEntries('testapp', 'errors', 50);

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
        $result = $appWatcher->getAppLogEntries('testapp', 'errors', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);

        // Should filter for errors using Monolog parser
        foreach ($parsed['logs/monolog.log'] as $entry) {
            $this->assertParsedLogEntry($entry);
            $this->assertSame('Error', $entry['type']);
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
        $result = $appWatcher->getAppLogEntries('testapp', 'warnings', 50);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);

        // Should filter for warnings using JSON parser
        foreach ($parsed['logs/json.log'] as $entry) {
            $this->assertParsedLogEntry($entry);
            $this->assertSame('Warning', $entry['type']);
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
        $this->assertArrayHasKey('logs/php_error.log', $result);
        $this->assertLogStatusEntry($result['logs/php_error.log']);
        $this->assertGreaterThan(0, $result['logs/php_error.log']['size']);
        $this->assertGreaterThan(0, $result['logs/php_error.log']['lines']);
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
        $this->assertArrayHasKey('logs/monolog.log', $result);
        $this->assertLogStatusEntry($result['logs/monolog.log']);
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
        $this->assertArrayHasKey('logs/json.log', $result);
        $this->assertLogStatusEntry($result['logs/json.log']);
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
        $result1 = $appWatcher->getAppLogEntries('testapp', 'errors', 50);
        $result2 = $appWatcher->getAppLogEntries('testapp', 'errors', 50);
        $result3 = $appWatcher->getAppLogEntries('testapp', 'errors', 50);

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

        $phpResult = $appWatcher->getAppLogEntries('phpapp', 'errors', 10);
        $monologResult = $appWatcher->getAppLogEntries('monologapp', 'errors', 10);
        $jsonResult = $appWatcher->getAppLogEntries('jsonapp', 'errors', 10);

        $this->assertIsArray($phpResult);
        $this->assertIsArray($monologResult);
        $this->assertIsArray($jsonResult);

        $phpParsed = $phpResult;
        $monologParsed = $monologResult;
        $jsonParsed = $jsonResult;

        $this->assertArrayHasKey('logs/php_error.log', $phpParsed);
        $this->assertArrayHasKey('logs/monolog.log', $monologParsed);
        $this->assertArrayHasKey('logs/json.log', $jsonParsed);

        foreach ($phpParsed['logs/php_error.log'] as $entry) {
            $this->assertParsedLogEntry($entry);
            $this->assertSame('Error', $entry['type']);
        }

        foreach ($monologParsed['logs/monolog.log'] as $entry) {
            $this->assertParsedLogEntry($entry);
            $this->assertSame('Error', $entry['type']);
        }

        foreach ($jsonParsed['logs/json.log'] as $entry) {
            $this->assertParsedLogEntry($entry);
            $this->assertSame('Error', $entry['type']);
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
        $result = $appWatcher->getAppLogEntries('testapp', 'errors', 50);

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
        $result = $appWatcher->getAppLogEntries('testapp', '', 50);

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
        $result = $appWatcher->getAppLogEntries('testapp', '', 3);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertLessThanOrEqual(3, count($parsed['logs/php_error.log']));
    }

    public function testGetLogEntriesClampsZeroLimit(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getAppLogEntries('testapp', '', 0);

        $this->assertCount(1, $result['logs/php_error.log']);
    }

    public function testGetAppLogReadoutLimitClampsInvalidValues(): void
    {
        $watcherConfig = ['testapp' => ['logs' => []]];
        $appWatcher = $this->createAppWatcher($watcherConfig, transmitConfig: ['errorlog_readout_limit' => 25]);

        $this->assertSame(1, $appWatcher->getAppLogReadoutLimit(0));
        $this->assertSame(1, $appWatcher->getAppLogReadoutLimit(-10));
        $this->assertSame(25, $appWatcher->getAppLogReadoutLimit());
        $this->assertSame(12, $appWatcher->getAppLogReadoutLimit(12));
        $this->assertSame(1, $this->createAppWatcher($watcherConfig, transmitConfig: ['errorlog_readout_limit' => 0])->getAppLogReadoutLimit());
        $this->assertSame(50, $this->createAppWatcher($watcherConfig)->getAppLogReadoutLimit());
    }

    public function testGetLogEntriesAlwaysReturnsArrayGroups(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => [
                    'logs/php_error.log',
                    'logs/missing.log',
                    [
                        'type' => [],
                        'paths' => ['logs/json.log'],
                    ],
                ],
            ],
        ];

        $entries = $this->createAppWatcher($watcherConfig)->getAppLogEntries('testapp');

        $this->assertArrayHasKey('logs/php_error.log', $entries);
        $this->assertArrayHasKey('logs/missing.log', $entries);
        $this->assertArrayHasKey('logs/json.log', $entries);
        foreach ($entries as $group) {
            $this->assertIsArray($group);
        }
    }

    public function testGetLogEntriesReturnsEmptyForInvalidFilter(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];
        $logger = new class () extends AbstractLogger {
            /** @var array<int,array{level: mixed, message: string, context: array<string,mixed>}> */
            public array $records = [];

            /**
             * @param array<string,mixed> $context
             * @param mixed               $level
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $appWatcher = $this->createAppWatcher($watcherConfig, $logger);
        $result = $appWatcher->getAppLogEntries('testapp', 'not-supported', 10);

        $this->assertSame([], $result);
        $this->assertSame('Invalid log filter requested', $logger->records[0]['message'] ?? null);
        $this->assertSame('not-supported', $logger->records[0]['context']['filter'] ?? null);
    }

    public function testGetLogEntriesIncludesStackTraceLines(): void
    {
        $watcherConfig = [
            'testapp' => [
                'logs' => ['logs/php_error.log'],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);
        $result = $appWatcher->getAppLogEntries('testapp', '', 50);
        $parsed = $result;

        $stackTraceFound = false;
        $stackFrameFound = false;
        foreach ($parsed['logs/php_error.log'] as $entry) {
            $this->assertParsedLogEntry($entry);
            if (str_contains($entry['message'], 'Stack trace')) {
                $stackTraceFound = true;
            }
            if (str_contains($entry['message'], '#0')) {
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
        $result = $appWatcher->getAppLogEntries('testapp', 'errors', 50);

        $this->assertIsArray($result);
        $parsed = $result;

        // Should use Monolog parser
        foreach ($parsed['logs/monolog.log'] as $entry) {
            $this->assertParsedLogEntry($entry);
            $this->assertSame('Error', $entry['type']);
        }
    }

    private function assertParsedLogEntry(mixed $entry): void
    {
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('type', $entry);
        $this->assertArrayHasKey('message', $entry);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['timestamp']);
        $this->assertContains($entry['type'], ['Error', 'Warning', 'Deprecated', 'Other']);
        $this->assertIsString($entry['message']);
    }

    private function assertLogStatusEntry(mixed $entry): void
    {
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('size', $entry);
        $this->assertArrayHasKey('lines', $entry);
        $this->assertArrayHasKey('last_modified', $entry);
        $this->assertIsInt($entry['size']);
        $this->assertIsInt($entry['lines']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['last_modified']);
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
