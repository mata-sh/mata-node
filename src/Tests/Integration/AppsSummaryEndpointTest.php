<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use GuzzleHttp\Client;
use Mata\Tests\Support\HttpTestServer;
use Mata\Watchers\LogParsers\PhpErrorLogParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * `GET /apps` monitoring summary contract over real HTTP.
 *
 * @internal
 *
 * @coversNothing
 */
final class AppsSummaryEndpointTest extends TestCase
{
    private const SERVER_ID = 'dashboard';
    private const API_KEY = 'apps-summary-test-key';

    private string $tmpDir;
    private ?HttpTestServer $server = null;
    private ?Client $client = null;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for HTTP integration tests.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/mata-apps-summary-' . bin2hex(random_bytes(6));
        $this->createTestAppTree($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;

        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    public function testAppsSummaryReturnsMonitoringFlagsAndCursorCountPairing(): void
    {
        $fatalLog = "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: Uncaught PDOException: Connection refused in /var/www/shop/Db.php:23\n"
            . "Stack trace:\n#0 {main}\n"
            . "[19-Jul-2026 12:00:01 UTC] PHP Warning: Undefined array key \"user_id\"\n";
        $this->writeAppLog('shop', 'error.log', $fatalLog);
        $this->writeAppLog('blog', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Warning: Undefined array key \"post_id\"\n");

        $this->startServer([
            'shop' => $this->appConfig('Shop', 'shop', [
                'monitoring' => ['sessions' => false, 'alarms' => false],
            ]),
            'blog' => $this->appConfig('Blog', 'blog'),
        ]);

        $response = $this->client()->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decodeJson($response);

        $shop = $body['shop'];
        $this->assertSame(['sessions' => false, 'alarms' => false], $shop['monitoring']);
        $this->assertArrayNotHasKey('session', $shop);
        $this->assertSame(1, $shop['status']['fatal']);
        $this->assertSame(1, $shop['status']['warnings']);
        $this->assertSame(4, $shop['status']['total']);
        $this->assertMatchesRegularExpression('/^v1:[0-9a-f]{64}$/', $shop['status']['fatal_cursor']);
        $this->assertSame(['shop/logs/error.log' => strlen($fatalLog) / 1024], $shop['logs']);

        $blog = $body['blog'];
        $this->assertArrayNotHasKey('monitoring', $blog);
        $this->assertSame(0, $blog['status']['fatal']);
        $this->assertNull($blog['status']['fatal_cursor']);
        $this->assertSame(['fatal', 'warnings', 'total', 'fatal_cursor'], array_keys($blog['status']));
        $this->assertSame(
            ['path', 'title', 'url', 'env', 'status_url', 'description', 'composer', 'status', 'session', 'logs'],
            array_keys($blog)
        );
    }

    public function testAppsSummaryOmitsMeasuredKeysForDisabledMonitoring(): void
    {
        $this->writeAppLog('shop', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: boom\n");

        $this->startServer([
            'shop' => $this->appConfig('Shop', 'shop', [
                'monitoring' => ['loganalysis' => false, 'logsize' => false, 'sessions' => false],
            ]),
        ]);

        $response = $this->client()->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $shop = $this->decodeJson($response)['shop'];

        $this->assertSame(
            ['path', 'title', 'url', 'env', 'status_url', 'description', 'composer', 'monitoring'],
            array_keys($shop)
        );
        $this->assertSame(
            ['loganalysis' => false, 'logsize' => false, 'sessions' => false],
            $shop['monitoring']
        );
    }

    public function testAppsSummaryNoLongerExposesPingOrTheRemovedFlatFlags(): void
    {
        $this->writeAppLog('shop', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Warning: careful\n");

        $this->startServer([
            // Legacy keys left over in a deployed config must be ignored, not echoed.
            'shop' => $this->appConfig('Shop', 'shop', [
                'ping' => 'off',
                'error_monitoring_enabled' => false,
                'logsize_monitoring_enabled' => false,
            ]),
        ]);

        $raw = (string) $this->client()->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ])->getBody();

        $this->assertStringNotContainsString('ping', $raw);
        $this->assertStringNotContainsString('error_monitoring_enabled', $raw);
        $this->assertStringNotContainsString('logsize_monitoring_enabled', $raw);
    }

    #[DataProvider('gatedDetailRouteProvider')]
    public function testDisabledMonitoringRefusesDetailRouteWithConflict(string $setting, string $path): void
    {
        $this->writeAppLog('shop', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: boom\n");
        $this->startServer([
            'shop' => $this->appConfig('Shop', 'shop', ['monitoring' => [$setting => false]]),
        ]);

        $response = $this->client()->get($path, [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([
            'error' => 'Monitoring disabled',
            'message' => 'Monitoring disabled',
            'details' => ['app' => 'shop', 'setting' => $setting],
        ], $this->decodeJson($response));
    }

    public static function gatedDetailRouteProvider(): array
    {
        return [
            'logs need loganalysis' => ['loganalysis', '/shop/logs'],
            'logs-status needs logsize' => ['logsize', '/shop/logs-status'],
            'sessions need sessions' => ['sessions', '/shop/sessions'],
        ];
    }

    public function testAppsSummaryDoesNotExposeCursorSourceMaterialOrLogEntries(): void
    {
        $this->writeAppLog('shop', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: Uncaught PDOException: Connection refused\n");
        $this->startServer(['shop' => $this->appConfig('Shop', 'shop')]);

        $logFile = $this->tmpDir . '/shop/logs/error.log';
        $perFileDigest = (new PhpErrorLogParser())->analyzeLog($logFile)->fatalDigest;
        $this->assertIsString($perFileDigest);

        $raw = (string) $this->client()->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ])->getBody();

        $this->assertStringNotContainsString($perFileDigest, $raw);
        $this->assertStringNotContainsString('PDOException', $raw);
        $this->assertStringNotContainsString('Fatal error', $raw);
        $this->assertStringNotContainsString($this->tmpDir, $raw);
        $this->assertStringNotContainsString((string) fileinode($logFile), $raw);
        $this->assertStringNotContainsString('entries', $raw);
        $this->assertStringNotContainsString('last_modified', $raw);
    }

    public function testAppsSummaryDoesNotDispatchToLogEndpoints(): void
    {
        $this->writeAppLog('shop', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: boom\n");
        $this->startServer(['shop' => $this->appConfig('Shop', 'shop')]);

        $response = $this->client()->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $log = (string) file_get_contents($this->tmpDir . '/logs/app.log');
        $this->assertSame(1, substr_count($log, 'Authorized HTTP request'));
        $this->assertStringContainsString('"request_uri":"/apps"', $log);
        $this->assertStringNotContainsString('/shop/logs', $log);
        $this->assertStringNotContainsString('/shop/logs-status', $log);
    }

    public function testInvalidMonitoringSettingReturnsSafeConfigurationError(): void
    {
        $this->writeAppLog('shop', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: boom\n");
        $this->startServer([
            'shop' => $this->appConfig('Shop', 'shop', ['monitoring' => ['loganalysis' => 'true']]),
        ]);

        $response = $this->client()->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(500, $response->getStatusCode());
        $raw = (string) $response->getBody();
        $this->assertSame([
            'error' => 'Invalid application configuration',
            'message' => 'Invalid application configuration',
            'details' => null,
        ], $this->decodeJson($response));
        $this->assertStringNotContainsString('monitoring', $raw);
        $this->assertStringNotContainsString('loganalysis', $raw);
    }

    public function testUnknownMonitoringKeyReturnsSafeConfigurationError(): void
    {
        $this->writeAppLog('shop', 'error.log', "[19-Jul-2026 12:00:00 UTC] PHP Fatal error: boom\n");
        $this->startServer([
            // A typo must fail loudly instead of silently resolving to enabled.
            'shop' => $this->appConfig('Shop', 'shop', ['monitoring' => ['logsizes' => false]]),
        ]);

        $response = $this->client()->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('logsizes', (string) $response->getBody());
    }

    public function testAppsSummaryStillRequiresAuthentication(): void
    {
        $this->startServer(['shop' => $this->appConfig('Shop', 'shop')]);

        $response = $this->client()->get('/apps');

        $this->assertSame(401, $response->getStatusCode());
        $body = $this->decodeJson($response);
        $this->assertSame('Unauthorized', $body['error']);
        $this->assertSame('Missing X-Mata-Auth header', $body['message']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function appConfig(string $title, string $path, array $overrides = []): array
    {
        return array_replace([
            'title' => $title,
            'url' => 'https://example.test/' . $path,
            'path' => $path,
            'env' => 'prod',
            'logs' => [
                [
                    'type' => 'php_error_log',
                    'paths' => [$path . '/logs/error.log'],
                ],
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $apps
     */
    private function startServer(array $apps): void
    {
        file_put_contents(
            $this->tmpDir . '/config/watcher-apps.json',
            (string) json_encode($apps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->server = new HttpTestServer(
            $this->tmpDir . '/public',
            $this->tmpDir . '/public/index.php',
            [
                'MATA_HTTP_TEST_PROJECT_ROOT' => dirname(__DIR__, 3),
                'MATA_HTTP_TEST_BASE_PATH' => $this->tmpDir,
                'MATA_HTTP_TEST_SERVER_ID' => self::SERVER_ID,
                'MATA_HTTP_TEST_API_KEY' => self::API_KEY,
                'MATA_HTTP_TEST_APPS_FILE' => $this->tmpDir . '/config/watcher-apps.json',
            ]
        );
        $this->server->start();
        $this->waitForServer();
    }

    private function client(): Client
    {
        $this->client ??= new Client([
            'base_uri' => $this->server?->baseUrl(),
            'http_errors' => false,
            'timeout' => 5,
        ]);

        return $this->client;
    }

    private function waitForServer(): void
    {
        $lastError = '';
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if ($this->server === null || !$this->server->isRunning()) {
                $this->fail('HTTP test server exited early. ' . ($this->server?->stderr() ?? ''));
            }

            try {
                $response = $this->client()->get('/health');
                if ($response->getStatusCode() === 200) {
                    return;
                }
                $lastError = 'status ' . $response->getStatusCode() . ': ' . (string) $response->getBody();
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                usleep(100000);
            }
        }

        $this->fail('HTTP test server did not become ready. ' . $lastError);
    }

    private function generateAuthHeader(): string
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $payload = self::SERVER_ID . '|' . $timestamp . '|' . $nonce;

        return $payload . '|' . hash_hmac('sha256', $payload, self::API_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function writeAppLog(string $app, string $name, string $contents): void
    {
        $directory = $this->tmpDir . '/' . $app . '/logs';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($directory . '/' . $name, $contents);
    }

    private function createTestAppTree(string $basePath): void
    {
        mkdir($basePath . '/public', 0775, true);
        mkdir($basePath . '/config', 0775, true);
        mkdir($basePath . '/logs', 0775, true);
        mkdir($basePath . '/cache', 0775, true);
        file_put_contents($basePath . '/config/config.env', 'DASHBOARD_API_KEY=' . self::API_KEY . "\n");
        file_put_contents($basePath . '/config/apps.json', '{}');
        file_put_contents($basePath . '/config/databases.json', '{}');
        file_put_contents($basePath . '/config/transmit.json', '{}');
        file_put_contents($basePath . '/public/index.php', $this->frontControllerSource());
    }

    private function frontControllerSource(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use Bramus\Router\Router;
            use Mata\Config\Dto\AuthConfig;
            use Mata\Config\Dto\GeneralConfig;
            use Mata\Config\Dto\RateLimitingConfig;
            use Mata\Core\Auth;
            use Mata\Factory\NonceStoreFactory;
            use Mata\Factory\RateLimiterFactory;
            use Mata\Http\RouterSetup;
            use Mata\Services\FileCache;
            use Mata\Tests\Support\FakeCommandRunner;
            use Mata\Utils\PathResolver;
            use Mata\Utils\Tools;
            use Mata\Watchers\AppWatcher;
            use Mata\Watchers\DatabaseWatcher;
            use Mata\Watchers\ServerWatcher;
            use Monolog\Handler\StreamHandler;
            use Monolog\Level;
            use Monolog\Logger;

            $projectRoot = getenv('MATA_HTTP_TEST_PROJECT_ROOT');
            $basePath = getenv('MATA_HTTP_TEST_BASE_PATH');
            $serverId = getenv('MATA_HTTP_TEST_SERVER_ID') ?: 'dashboard';
            $apiKey = getenv('MATA_HTTP_TEST_API_KEY') ?: '';
            $appsFile = getenv('MATA_HTTP_TEST_APPS_FILE');

            require $projectRoot . '/vendor/autoload.php';

            $logger = new Logger('apps-summary-test');
            $logger->pushHandler(new StreamHandler($basePath . '/logs/app.log', Level::Debug));

            $paths = new PathResolver($projectRoot . '/public/index.php');
            $paths->appPath = $basePath;
            $paths->rootPath = $basePath;
            $paths->logsPath = $basePath . '/logs';
            $paths->appLogPath = $paths->logsPath . '/app.log';
            $paths->bootLogPath = $paths->logsPath . '/boot.log';
            $paths->tempPath = $basePath;
            $paths->cachePath = $basePath . '/cache';

            $auth = new Auth(
                new AuthConfig($serverId, $apiKey, 120),
                NonceStoreFactory::create($paths->cachePath)
            );

            $rateLimiter = RateLimiterFactory::create(new RateLimitingConfig(
                enabled: true,
                storageType: 'file',
                filePath: $paths->cachePath . '/rate_limit',
                redisHost: null,
                redisPort: 6379,
                redisPassword: null,
                redisDatabase: 0,
                loginMaxRequests: 100,
                loginWindowSeconds: 60,
                dashboardMaxRequests: 100,
                dashboardWindowSeconds: 60,
                apiMaxRequests: 100,
                apiWindowSeconds: 60,
                authFailedMaxRequests: 100,
                authFailedWindowSeconds: 60,
            ), $logger);

            $cache = new FileCache($paths->cachePath);
            $commandRunner = new FakeCommandRunner();
            $transmitConfig = ['check_disk_space_enabled' => 'off'];
            $watcherConfig = json_decode((string) file_get_contents((string) $appsFile), true) ?: [];

            $routerSetup = new RouterSetup(
                new Router(),
                $auth,
                $logger,
                $paths,
                new AppWatcher(new Tools(), $logger, $paths->rootPath, $watcherConfig, $transmitConfig, null, $cache, $commandRunner),
                new ServerWatcher($paths->rootPath, $cache, $transmitConfig, $commandRunner),
                new DatabaseWatcher(null),
                new GeneralConfig(false, false, [], []),
                $rateLimiter
            );

            $routerSetup->setup();
            $routerSetup->run();
            PHP;
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $item) {
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
