<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal
 *
 * @coversNothing
 */
final class HttpAuthMiddlewareTest extends TestCase
{
    private const SERVER_ID = 'dashboard';
    private const API_KEY = 'http-middleware-test-key';

    private string $tmpDir;
    private string $baseUrl;
    private Client $client;

    /** @var null|resource */
    private mixed $serverProcess = null;

    /** @var array<int,resource> */
    private array $serverPipes = [];

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for HTTP integration tests.');
        }

        $this->tmpDir = sys_get_temp_dir() . '/mata-http-auth-' . bin2hex(random_bytes(6));
        $this->createTestAppTree($this->tmpDir);

        $port = $this->allocatePort();
        $this->baseUrl = 'http://127.0.0.1:' . $port;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'timeout' => 3,
        ]);

        $this->startServer($port);
        $this->waitForServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    public function testHealthRouteBypassesAuthMiddleware(): void
    {
        $response = $this->client->get('/health');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decodeJson($response);
        $this->assertSame('healthy', $body['status']);

        $log = file_get_contents($this->tmpDir . '/logs/app.log');
        $this->assertIsString($log);
        $this->assertStringContainsString('Health check HTTP request', $log);
        $this->assertStringContainsString('"request_uri":"/health"', $log);
    }

    public function testProtectedRouteAcceptsValidHmacHeaderThroughRouter(): void
    {
        $response = $this->client->get('/server/info', [
            'headers' => [
                'X-Mata-Auth' => $this->generateAuthHeader(),
            ],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decodeJson($response);
        $this->assertArrayHasKey('php_version', $body);

        $log = file_get_contents($this->tmpDir . '/logs/app.log');
        $this->assertIsString($log);
        $this->assertStringContainsString('Authorized HTTP request', $log);
        $this->assertStringContainsString('"server_id":"dashboard"', $log);
        $this->assertStringContainsString('"method":"GET"', $log);
        $this->assertStringContainsString('"request_uri":"/server/info"', $log);
    }

    public function testMissingReadmeReturnsPayloadWithOkStatusThroughRouter(): void
    {
        $response = $this->client->get('/testapp/readme', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([
            'content' => '',
            'notice' => [
                'code' => 'README_NOT_FOUND',
                'message' => 'README not found',
            ],
        ], $this->decodeJson($response));
    }

    public function testLogsRouteReturnsWrappedContract(): void
    {
        $response = $this->client->get('/testapp/logs?limit=0', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([
            'entries' => [],
            'readout_limit' => 1,
        ], $this->decodeJson($response));
    }

    public function testInvalidAppMetadataReturnsConfigurationErrorThroughRouter(): void
    {
        $response = $this->client->get('/apps', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(500, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decodeJson($response);
        $this->assertSame('Invalid application configuration', $body['error']);
        $this->assertSame('Invalid application configuration', $body['message']);
    }

    public function testProtectedRouteRejectsMissingAuthHeaderThroughRouter(): void
    {
        $response = $this->client->get('/server/info');

        $this->assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decodeJson($response);
        $this->assertSame('Unauthorized', $body['error']);
        $this->assertSame('Missing X-Mata-Auth header', $body['message']);
    }

    public function testRemovedAppConfigRouteUsesNormalNotFoundResponse(): void
    {
        $response = $this->client->get('/testapp/app-config', [
            'headers' => ['X-Mata-Auth' => $this->generateAuthHeader()],
        ]);

        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testReplayIsRejectedAcrossRealHttpRequestsUsingFileNonceStore(): void
    {
        $authHeader = $this->generateAuthHeader(nonce: 'replay-nonce-1234567890');

        $first = $this->client->get('/server/info', [
            'headers' => ['X-Mata-Auth' => $authHeader],
        ]);
        $this->assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $this->assertCount(1, glob($this->tmpDir . '/cache/auth_nonces/*.json') ?: []);

        $replay = $this->client->get('/server/info', [
            'headers' => ['X-Mata-Auth' => $authHeader],
        ]);

        $this->assertSame(401, $replay->getStatusCode(), (string) $replay->getBody());
        $body = $this->decodeJson($replay);
        $this->assertSame('Unauthorized', $body['error']);
        $this->assertSame('Nonce already used (replay attack detected)', $body['message']);
    }

    private function generateAuthHeader(?string $nonce = null): string
    {
        $timestamp = time();
        $nonce ??= bin2hex(random_bytes(16));
        $payload = self::SERVER_ID . '|' . $timestamp . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, self::API_KEY);

        return $payload . '|' . $signature;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function createTestAppTree(string $basePath): void
    {
        mkdir($basePath . '/public', 0775, true);
        mkdir($basePath . '/config', 0775, true);
        mkdir($basePath . '/logs', 0775, true);
        mkdir($basePath . '/cache', 0775, true);
        file_put_contents($basePath . '/config/config.env', 'DASHBOARD_API_KEY=' . self::API_KEY . "\n");
        file_put_contents($basePath . '/config/mail.config.env', '');
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

require $projectRoot . '/vendor/autoload.php';

$logger = new Logger('http-auth-test');
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
$watcherConfig = [
    'testapp' => [
        'title' => 'Test App',
        'url' => 'https://example.test',
        'path' => 'testapp',
        'env' => 'test',
        'logs' => [],
    ],
    'invalidapp' => [
        'title' => 'Invalid App',
        'url' => 'https://example.test/invalid',
        'path' => 'invalidapp',
        'logs' => [],
    ],
];

$routerSetup = new RouterSetup(
    new Router(),
    $auth,
    $logger,
    $paths,
    new AppWatcher(new Tools(), $logger, $paths->rootPath, $watcherConfig, $transmitConfig, null, $cache, $commandRunner),
    new ServerWatcher($paths->rootPath, $cache, $transmitConfig, $commandRunner),
    new DatabaseWatcher([], $commandRunner),
    new GeneralConfig(false, false, [], []),
    $rateLimiter
);

$routerSetup->setup();
$routerSetup->run();
PHP;
    }

    private function allocatePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->assertIsString($name);

        return (int) substr(strrchr($name, ':'), 1);
    }

    private function startServer(int $port): void
    {
        $frontController = $this->tmpDir . '/public/index.php';
        $command = sprintf(
            '%s -d variables_order=EGPCS -S 127.0.0.1:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($this->tmpDir . '/public'),
            escapeshellarg($frontController)
        );

        $this->serverProcess = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->serverPipes,
            null,
            [
                'MATA_HTTP_TEST_PROJECT_ROOT' => dirname(__DIR__, 3),
                'MATA_HTTP_TEST_BASE_PATH' => $this->tmpDir,
                'MATA_HTTP_TEST_SERVER_ID' => self::SERVER_ID,
                'MATA_HTTP_TEST_API_KEY' => self::API_KEY,
            ]
        );

        $this->assertIsResource($this->serverProcess);
    }

    private function waitForServer(): void
    {
        $lastError = '';
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->serverProcess);
            if (($status['running'] ?? false) !== true) {
                $stderr = is_resource($this->serverPipes[2] ?? null) ? stream_get_contents($this->serverPipes[2]) : '';
                $this->fail('HTTP test server exited early. ' . $stderr);
            }

            try {
                $response = $this->client->get('/health');
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

    private function removeDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                @unlink($itemPath);
            }
        }

        @rmdir($path);
    }
}
