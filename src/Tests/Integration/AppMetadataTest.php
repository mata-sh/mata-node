<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\Exceptions\AppWatcherException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Watchers\AppWatcher
 *
 * @internal
 */
class AppMetadataTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/mata-node-app-metadata-' . bin2hex(random_bytes(6));
        mkdir($this->rootPath . '/app/temp/sessions', 0777, true);
        mkdir($this->rootPath . '/app/includes', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootPath);
    }

    public function testAppInfoIncludesStaticMetadataAndNestedSessionMetadata(): void
    {
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_recent', 'recent-session');
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_old', 'old-session');
        file_put_contents($this->rootPath . '/app/temp/sessions/ignored', 'ignored-session');
        touch($this->rootPath . '/app/temp/sessions/sess_recent', time() - 60);
        touch($this->rootPath . '/app/temp/sessions/sess_old', time() - 7200);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([
                'status_endpoint' => 'https://example.com/test/health/',
            ]),
        ]);

        $info = $appWatcher->getAppInfo('testapp');

        $this->assertSame('App description', $info['description']);
        $this->assertSame('test', $info['env']);
        $this->assertSame('https://example.com/test/health/', $info['status_url']);
        $this->assertSame(['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null], $info['status']);
        $this->assertSame([], $info['logs']);
        $this->assertArrayNotHasKey('session_count_last_hour', $info);
        $this->assertArrayNotHasKey('session_count_last_day', $info);
        $this->assertArrayNotHasKey('session_count_total', $info);
        $this->assertArrayNotHasKey('session_path', $info);

        foreach (['environment', 'maintenance', 'configs', 'display_errors', 'log_level', 'log_helper', 'status_endpoint'] as $field) {
            $this->assertArrayNotHasKey($field, $info);
        }

        $this->assertSame([
            'type' => 'file',
            'status' => 'ok',
            'count_last_hour' => 1,
            'count_last_day' => 2,
            'count_total' => 2,
            'path' => 'app/temp/sessions',
            'size_kb' => round((strlen('recent-session') + strlen('old-session')) / 1024, 2),
        ], $info['session']);
    }

    public function testAppInfoReportsComposerCapability(): void
    {
        $appWatcher = $this->createAppWatcher([
            'disabled' => $this->appConfig(['composer' => ['enabled' => false]]),
            'enabled' => $this->appConfig(['composer' => ['enabled' => true]]),
            'configured' => $this->appConfig(['composer' => [
                'json' => 'app/composer.json',
                'lock' => 'app/composer.lock',
            ]]),
            'missing' => $this->appConfig(),
        ]);

        $this->assertFalse($appWatcher->getAppInfo('disabled')['composer']);
        $this->assertTrue($appWatcher->getAppInfo('enabled')['composer']);
        $this->assertTrue($appWatcher->getAppInfo('configured')['composer']);
        $this->assertFalse($appWatcher->getAppInfo('missing')['composer']);
    }

    #[DataProvider('validStatusEndpointProvider')]
    public function testAppInfoReturnsValidAbsoluteStatusUrl(string $statusEndpoint): void
    {
        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig(['status_endpoint' => $statusEndpoint]),
        ]);

        $this->assertSame($statusEndpoint, $appWatcher->getAppInfo('testapp')['status_url']);
    }

    public static function validStatusEndpointProvider(): array
    {
        return [
            'http' => ['http://example.com/health'],
            'https' => ['https://example.com/health/'],
        ];
    }

    #[DataProvider('emptyStatusEndpointProvider')]
    public function testAppInfoReturnsNullWithoutStatusEndpoint(array $overrides): void
    {
        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig($overrides),
        ]);

        $this->assertNull($appWatcher->getAppInfo('testapp')['status_url']);
    }

    public static function emptyStatusEndpointProvider(): array
    {
        return [
            'absent' => [[]],
            'empty' => [['status_endpoint' => '']],
        ];
    }

    #[DataProvider('invalidStatusEndpointProvider')]
    public function testInvalidStatusEndpointIsLoggedAndRejected(mixed $statusEndpoint): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig(['status_endpoint' => $statusEndpoint]),
        ], $logger);

        $this->assertInvalidConfiguration($appWatcher, $handler, 'status_endpoint');
    }

    public static function invalidStatusEndpointProvider(): array
    {
        return [
            'relative' => ['health'],
            'leading slash' => ['/health'],
            'non-http scheme' => ['ftp://example.com/health'],
            'fragment' => ['https://example.com/health#section'],
            'whitespace only' => ['   '],
            'null' => [null],
            'non-string' => [true],
            'malformed' => ['https://'],
        ];
    }

    public function testAppInfoAcceptsProdEnvironment(): void
    {
        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig(['env' => 'prod']),
        ]);

        $this->assertSame('prod', $appWatcher->getAppInfo('testapp')['env']);
    }

    #[DataProvider('invalidRequiredMetadataProvider')]
    public function testInvalidRequiredMetadataIsLoggedAndRejected(string $field, mixed $value, bool $unset = false): void
    {
        $config = $this->appConfig();
        if ($unset) {
            unset($config[$field]);
        } else {
            $config[$field] = $value;
        }

        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $appWatcher = $this->createAppWatcher(['testapp' => $config], $logger);

        $this->assertInvalidConfiguration($appWatcher, $handler, $field);
    }

    public static function invalidRequiredMetadataProvider(): array
    {
        $cases = [];
        foreach (['title', 'url', 'path', 'env'] as $field) {
            $cases[$field . ' missing'] = [$field, null, true];
            $cases[$field . ' empty'] = [$field, ''];
            $cases[$field . ' non-string'] = [$field, 42];
        }
        $cases['legacy live env'] = ['env', 'live'];

        return $cases;
    }

    public function testMonitoringSettingsDefaultToEnabledWhenOmitted(): void
    {
        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig(),
        ]);

        $info = $appWatcher->getAppInfo('testapp');
        $this->assertTrue($info['error_monitoring_enabled']);
        $this->assertTrue($info['logsize_monitoring_enabled']);

        $apps = $appWatcher->getApps();
        $this->assertTrue($apps['testapp']['error_monitoring_enabled']);
        $this->assertTrue($apps['testapp']['logsize_monitoring_enabled']);
    }

    #[DataProvider('monitoringSettingCombinationProvider')]
    public function testMonitoringSettingsVaryIndependently(bool $errorMonitoring, bool $logsizeMonitoring): void
    {
        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([
                'error_monitoring_enabled' => $errorMonitoring,
                'logsize_monitoring_enabled' => $logsizeMonitoring,
            ]),
        ]);

        $info = $appWatcher->getAppInfo('testapp');

        $this->assertSame($errorMonitoring, $info['error_monitoring_enabled']);
        $this->assertSame($logsizeMonitoring, $info['logsize_monitoring_enabled']);
    }

    public static function monitoringSettingCombinationProvider(): array
    {
        return [
            'both enabled' => [true, true],
            'fatal only' => [true, false],
            'logsize only' => [false, true],
            'both disabled' => [false, false],
        ];
    }

    #[DataProvider('invalidMonitoringSettingProvider')]
    public function testInvalidMonitoringSettingIsLoggedAndRejected(string $field, mixed $value): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([$field => $value]),
        ], $logger);

        $this->assertInvalidConfiguration($appWatcher, $handler, $field);

        $records = $handler->getRecords();
        $this->assertSame(['app', 'field'], array_keys($records[0]['context']));
    }

    public static function invalidMonitoringSettingProvider(): array
    {
        $values = [
            'string true' => 'true',
            'string false' => 'false',
            'string on' => 'on',
            'string off' => 'off',
            'empty string' => '',
            'integer one' => 1,
            'integer zero' => 0,
            'float' => 1.0,
            'null' => null,
            'array' => ['enabled'],
            // json_decode() gives associative arrays, so this is how a JSON
            // object actually reaches the watcher config.
            'json object' => ['enabled' => true],
            'stdclass object' => (object) ['enabled' => true],
        ];

        $cases = [];
        foreach (['error_monitoring_enabled', 'logsize_monitoring_enabled'] as $field) {
            foreach ($values as $label => $value) {
                $cases[$field . ' ' . $label] = [$field, $value];
            }
        }

        return $cases;
    }

    public function testDisabledMonitoringStillReturnsStatusCursorAndLogSizes(): void
    {
        mkdir($this->rootPath . '/app/logs', 0777, true);
        $log = "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: boom\n[19-Jul-2026 12:00:01 UTC] PHP Warning: careful\n";
        file_put_contents($this->rootPath . '/app/logs/error.log', $log);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([
                'error_monitoring_enabled' => false,
                'logsize_monitoring_enabled' => false,
                'logs' => [['type' => 'php_error_log', 'paths' => ['app/logs/error.log']]],
            ]),
        ]);

        $info = $appWatcher->getAppInfo('testapp');

        $this->assertFalse($info['error_monitoring_enabled']);
        $this->assertFalse($info['logsize_monitoring_enabled']);
        $this->assertSame(1, $info['status']['fatal']);
        $this->assertSame(1, $info['status']['warnings']);
        $this->assertSame(2, $info['status']['total']);
        $this->assertMatchesRegularExpression('/^v1:[0-9a-f]{64}$/', (string) $info['status']['fatal_cursor']);
        $this->assertSame(['app/logs/error.log' => strlen($log) / 1024], $info['logs']);
    }

    public function testDisabledAppIsExcludedAndUnavailable(): void
    {
        $appWatcher = $this->createAppWatcher([
            'enabled' => $this->appConfig(),
            'disabled' => $this->appConfig(['disabled' => 'true']),
        ]);

        $this->assertSame(['enabled'], array_keys($appWatcher->getApps()));

        try {
            $appWatcher->getApp('disabled');
            $this->fail('Expected disabled app to be unavailable.');
        } catch (AppWatcherException $exception) {
            $this->assertSame(404, $exception->getHttpStatus());
        }
    }

    public function testAppSpecificSessionsEndpointPayload(): void
    {
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_recent', 'session');
        touch($this->rootPath . '/app/temp/sessions/sess_recent', time() - 60);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig(),
        ]);

        $sessions = $appWatcher->getAppSessions('testapp');

        $this->assertSame('file', $sessions['type']);
        $this->assertSame('ok', $sessions['status']);
        $this->assertSame(1, $sessions['count_last_hour']);
        $this->assertSame(1, $sessions['count_last_day']);
        $this->assertSame(1, $sessions['count_total']);
        $this->assertSame('app/temp/sessions', $sessions['path']);
        $this->assertSame(round(strlen('session') / 1024, 2), $sessions['size_kb']);
    }

    public function testMissingSessionConfigReportsUnconfiguredSessionBackend(): void
    {
        $config = $this->appConfig();
        unset($config['sessions']);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $config,
        ]);

        $expected = [
            'type' => 'none',
            'status' => 'unconfigured',
            'count_last_hour' => null,
            'count_last_day' => null,
            'count_total' => null,
            'path' => null,
            'size_kb' => null,
        ];

        $this->assertSame($expected, $appWatcher->getAppSessions('testapp'));
        $this->assertSame($expected, $appWatcher->getAppInfo('testapp')['session']);
    }

    private function assertInvalidConfiguration(AppWatcher $appWatcher, TestHandler $handler, string $field): void
    {
        try {
            $appWatcher->getAppInfo('testapp');
            $this->fail('Expected invalid application configuration exception.');
        } catch (AppWatcherException $exception) {
            $this->assertSame(500, $exception->getHttpStatus());
            $this->assertSame('Invalid application configuration', $exception->getError());
            $this->assertSame('Invalid application configuration', $exception->getMessage());
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('Invalid application configuration', $records[0]['message']);
        $this->assertSame('testapp', $records[0]['context']['app']);
        $this->assertSame($field, $records[0]['context']['field']);
    }

    private function createAppWatcher(array $watcherConfig, ?LoggerInterface $logger = null): AppWatcher
    {
        return new AppWatcher(
            new Tools(),
            $logger ?? new NullLogger(),
            $this->rootPath,
            $watcherConfig,
            ['errorlog_readout_limit' => 50],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function appConfig(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Test App',
            'url' => 'https://example.com/test',
            'path' => 'app',
            'env' => 'test',
            'description' => 'App description',
            'disabled' => 'false',
            'sessions' => [
                'type' => 'file',
                'path' => 'app/temp/sessions',
                'filename_regex' => '/^sess_/',
            ],
            'logs' => [],
        ], $overrides);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
