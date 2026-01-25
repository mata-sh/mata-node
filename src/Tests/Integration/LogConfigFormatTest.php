<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\LogParsers\LogParserFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for new log configuration format.
 *
 * Tests both old format (array of strings) and new format (array of objects with type/paths).
 *
 * @covers \Mata\Watchers\AppWatcher
 *
 * @internal
 */
class LogConfigFormatTest extends TestCase
{
    private string $fixturesPath;
    private string $rootPath;
    private Tools $tools;
    private ?string $previousErrorLog = null;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../Fixtures/logs';
        $this->rootPath = __DIR__ . '/../Fixtures';
        $this->tools = new Tools();

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

    public function testOldFormatSimpleArray(): void
    {
        $watcherConfig = [
            'oldapp' => [
                'logs' => [
                    'logs/php_error.log',
                    'logs/monolog.log',
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('oldapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertArrayHasKey('logs/monolog.log', $parsed);
        $this->assertNotEmpty($parsed['logs/php_error.log']);
    }

    public function testNewFormatWithTypeSpecified(): void
    {
        $watcherConfig = [
            'newapp' => [
                'logs' => [
                    [
                        'type' => 'php_error_log',
                        'paths' => [
                            'logs/php_error.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('newapp', 'errors', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertNotEmpty($parsed['logs/php_error.log']);

        foreach ($parsed['logs/php_error.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/PHP (Fatal error|Parse error|Error)/', $line);
        }
    }

    public function testNewFormatWithoutTypeAutoDetects(): void
    {
        $watcherConfig = [
            'autoapp' => [
                'logs' => [
                    [
                        'paths' => [
                            'logs/monolog.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('autoapp', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/monolog.log', $parsed);
        $this->assertNotEmpty($parsed['logs/monolog.log']);

        $this->assertNotEmpty($parsed['logs/monolog.log']);
    }

    public function testMultipleGroupsWithDifferentTypes(): void
    {
        $watcherConfig = [
            'mixedapp' => [
                'logs' => [
                    [
                        'type' => 'php_error_log',
                        'paths' => [
                            'logs/php_error.log',
                        ],
                    ],
                    [
                        'type' => 'monolog',
                        'paths' => [
                            'logs/monolog.log',
                        ],
                    ],
                    [
                        'type' => 'json',
                        'paths' => [
                            'logs/json.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('mixedapp', 'errors', 10);
        $parsed = $result;

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertArrayHasKey('logs/monolog.log', $parsed);
        $this->assertArrayHasKey('logs/json.log', $parsed);

        foreach ($parsed['logs/php_error.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/PHP (Fatal error|Parse error|Error)/', $line);
        }

        foreach ($parsed['logs/monolog.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/\.ERROR:|\.CRITICAL:|\.EMERGENCY:|\.ALERT:/', $line);
        }

        foreach ($parsed['logs/json.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/\"level\":\"(error|critical|emergency|alert)\"/', $line);
        }
    }

    public function testMultiplePathsInSingleGroup(): void
    {
        $watcherConfig = [
            'multipath' => [
                'logs' => [
                    [
                        'type' => 'monolog',
                        'paths' => [
                            'logs/monolog.log',
                            'logs/json.log', // Will use Monolog parser even though it's JSON
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('multipath', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/monolog.log', $parsed);
        $this->assertArrayHasKey('logs/json.log', $parsed);
        $this->assertNotEmpty($parsed['logs/monolog.log']);
        $this->assertNotEmpty($parsed['logs/json.log']);
    }

    public function testGetLogEntriesUsesFirstLog(): void
    {
        $watcherConfig = [
            'firstlogtest' => [
                'logs' => [
                    [
                        'type' => 'monolog',
                        'paths' => [
                            'logs/monolog.log',
                        ],
                    ],
                    [
                        'type' => 'json',
                        'paths' => [
                            'logs/json.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        // Should use first log (monolog.log)
        $result = $appWatcher->getLogEntries('firstlogtest', '', 10);
        $this->assertIsArray($result);
    }

    public function testGetLogEntriesOldFormat(): void
    {
        $watcherConfig = [
            'oldformat' => [
                'logs' => [
                    'logs/php_error.log',
                    'logs/monolog.log',
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        // Should use first log (php_error.log)
        $result = $appWatcher->getLogEntries('oldformat', '', 10);
        $this->assertIsArray($result);
    }

    public function testBackwardCompatibilityWithLogFormat(): void
    {
        // Old config with app-level log_format should still work
        $watcherConfig = [
            'oldstyle' => [
                'logs' => [
                    'logs/json.log', // JSON file
                ],
                'log_format' => 'monolog', // Force Monolog parser
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('oldstyle', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/json.log', $parsed);
        $this->assertNotEmpty($parsed['logs/json.log']);
    }

    public function testNewFormatOverridesAppLevelLogFormat(): void
    {
        // Log group type should take precedence over app-level log_format
        $watcherConfig = [
            'precedence' => [
                'logs' => [
                    [
                        'type' => 'json',
                        'paths' => [
                            'logs/json.log',
                        ],
                    ],
                ],
                'log_format' => 'monolog', // Should be ignored in favor of group type
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('precedence', 'warnings', 10);
        $parsed = $result;

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/json.log', $parsed);
        foreach ($parsed['logs/json.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringContainsString('"level":"warning"', $line);
        }
    }

    public function testMixedOldAndNewFormatGroups(): void
    {
        // Shouldn't happen in practice, but should handle gracefully
        $watcherConfig = [
            'mixed' => [
                'logs' => [
                    'logs/php_error.log', // Old format (string)
                    [
                        'type' => 'json',
                        'paths' => [
                            'logs/json.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('mixed', 'errors', 10);
        $this->assertIsArray($result);

        $parsed = $result;
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/php_error.log', $parsed);
        $this->assertArrayHasKey('logs/json.log', $parsed);

        foreach ($parsed['logs/php_error.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/PHP (Fatal error|Parse error|Error)/', $line);
        }

        foreach ($parsed['logs/json.log'] as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertMatchesRegularExpression('/\"level\":\"(error|critical|emergency|alert)\"/', $line);
        }
    }

    public function testGetLogEntriesWithEmptyLogsConfig(): void
    {
        $watcherConfig = [
            'nologs' => [
                'logs' => [],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('nologs', '', 10);
        $this->assertSame([], $result);
    }

    public function testGetLogEntriesWithMissingLogsConfig(): void
    {
        $watcherConfig = [
            'nologskey' => [
                'title' => 'No logs configured',
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('nologskey', '', 10);
        $this->assertSame([], $result);
    }

    public function testInvalidTypeInGroupFallsBackToAutoDetect(): void
    {
        $this->redirectErrorLog();
        $watcherConfig = [
            'invalidtype' => [
                'logs' => [
                    [
                        'type' => 'invalid_parser_type',
                        'paths' => [
                            'logs/monolog.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $result = $appWatcher->getLogEntries('invalidtype', '', 10);

        $this->assertIsArray($result);
        $parsed = $result;

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('logs/monolog.log', $parsed);
        $this->assertNotEmpty($parsed['logs/monolog.log']);
    }

    public function testParserCachingPerFile(): void
    {
        $watcherConfig = [
            'cachetest' => [
                'logs' => [
                    [
                        'type' => 'php_error_log',
                        'paths' => [
                            'logs/php_error.log',
                        ],
                    ],
                    [
                        'type' => 'json',
                        'paths' => [
                            'logs/json.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        // Call multiple times - should use cached parsers
        $result1 = $appWatcher->getLogEntries('cachetest', 'errors', 10);
        $result2 = $appWatcher->getLogEntries('cachetest', 'errors', 10);
        $result3 = $appWatcher->getLogEntries('cachetest', 'errors', 10);
        $result4 = $appWatcher->getLogEntries('cachetest', 'errors', 10);

        // Results should be identical (from cache)
        $this->assertEquals($result1, $result2);
        $this->assertEquals($result3, $result4);
    }

    public function testGetLogStatsWithNewFormat(): void
    {
        $watcherConfig = [
            'statstest' => [
                'logs' => [
                    [
                        'type' => 'php_error_log',
                        'paths' => [
                            'logs/php_error.log',
                        ],
                    ],
                ],
            ],
        ];

        $appWatcher = $this->createAppWatcher($watcherConfig);

        $statsJson = $appWatcher->getAppLogStatus('statstest');
        $this->assertIsArray($statsJson);
        $stats = $statsJson;

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('fatal', $stats);
        $this->assertArrayHasKey('warnings', $stats);
        $this->assertArrayHasKey('total', $stats);
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
