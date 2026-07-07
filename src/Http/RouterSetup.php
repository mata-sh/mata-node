<?php

declare(strict_types=1);

namespace Mata\Http;

use Bramus\Router\Router;
use Mata\Core\Auth;
use Mata\Services\RateLimiterService;
use Mata\Utils\PathResolver;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\DatabaseWatcher;
use Mata\Watchers\ServerWatcher;
use Psr\Log\LoggerInterface;
use Throwable;

class RouterSetup
{
    public function __construct(
        private Router $router,
        private Auth $auth,
        private LoggerInterface $logger,
        private PathResolver $paths,
        private AppWatcher $appWatcher,
        private ServerWatcher $serverWatcher,
        private DatabaseWatcher $databaseWatcher,
        private ?RateLimiterService $rateLimiterService = null
    ) {}

    public function setup(): void
    {
        $this->setupHealthCheck();
        $this->setupAuthAndRateLimiting();
        $this->setupRoutes();
    }

    public function run(): void
    {
        $this->router->run();
    }

    private function setupHealthCheck(): void
    {
        $this->router->get('/health', function () {
            $healthy = true;
            $checks = [];

            $checks['logs_writable'] = is_writable($this->paths->logsPath);
            if (!$checks['logs_writable']) {
                $healthy = false;
            }

            $checks['cache_writable'] = is_writable($this->paths->cachePath);
            if (!$checks['cache_writable']) {
                $healthy = false;
            }

            $checks['config_exists'] = file_exists($this->paths->appPath . '/config/config.env');
            if (!$checks['config_exists']) {
                $healthy = false;
            }

            $response = [
                'status' => $healthy ? 'healthy' : 'unhealthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'checks' => $checks,
            ];

            $this->sendJson($response, $healthy ? 200 : 503);
        });
    }

    private function setupAuthAndRateLimiting(): void
    {
        $this->router->before('GET|POST', '/(?!health$).*', function () {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            if ($this->rateLimiterService?->isRateLimited('api', $ip)) {
                $status = $this->rateLimiterService->check('api', $ip);
                $this->logger->warning('Rate limit exceeded', [
                    'group' => 'api',
                    'ip' => $ip,
                    'limit' => $status['limit'],
                    'current' => $status['current'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);
                $this->sendJson([
                    'error' => 'Rate limit exceeded',
                    'limit' => $status['limit'],
                    'reset_at' => $status['reset_at'],
                ], 429);

                exit;
            }

            $authResult = $this->auth->validateHmacAuth();

            if (!$authResult['success']) {
                if ($this->rateLimiterService?->isRateLimited('auth_failed', $ip)) {
                    $status = $this->rateLimiterService->check('auth_failed', $ip);
                    $this->logger->warning('Failed auth rate limit exceeded', [
                        'ip' => $ip,
                        'limit' => $status['limit'],
                        'current' => $status['current'],
                    ]);
                }

                $this->logger->warning('Authentication failed', [
                    'error' => $authResult['error'],
                    'ip' => $ip,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                $this->sendJson([
                    'error' => 'Unauthorized',
                    'message' => $authResult['error'],
                ], 401);

                exit;
            }

            $_SERVER['AUTHENTICATED_SERVER_ID'] = $authResult['serverId'];
        });
    }

    private function setupRoutes(): void
    {
        $serverRoutes = [
            ['/server-data', fn () => $this->serverWatcher->getServerData()],
            ['/server-info', fn () => $this->serverWatcher->getServerInfo()],
            ['/server-php', fn () => $this->serverWatcher->getPhpProcesses()],
        ];

        $appRoutes = [
            ['/apps', fn () => $this->appWatcher->getApps()],
            ['/apps/libraries', fn () => $this->appWatcher->getAppsLibraries()],
            ['/apps/composer-audit', fn () => $this->appWatcher->getAppsComposerAudit()],

            ['/{app}/readme', fn (string $app) => $this->appWatcher->getAppReadme($app)],
            ['/{app}/session-count', fn (string $app) => $this->appWatcher->getSessionCount(app: $app)],
            ['/{app}/session-count/{filter}', fn (string $app, string $filter) => $this->appWatcher->getSessionCount(app: $app, maxAgeSeconds: (int) $filter)],
            ['/{app}/logs', fn (string $app) => $this->appWatcher->getLogEntries(
                app: $app,
                filter: is_string($_GET['filter'] ?? null) ? $_GET['filter'] : '',
                limit: isset($_GET['limit']) && is_scalar($_GET['limit']) ? (int) $_GET['limit'] : null,
            )],
            ['/{app}/logs-status', fn (string $app) => $this->appWatcher->getAppLogStatus($app)],
            ['/{app}/app-config', fn (string $app) => $this->appWatcher->loadAppConfig($app)],
            ['/{app}/libraries', fn (string $app) => $this->appWatcher->getAppLibraries($app)],
        ];

        $databaseRoutes = [
            ['/server-databases', fn () => $this->databaseWatcher->getDatabasesStatus()],
            ['/{dbname}/database-status', fn (string $dbname) => $this->databaseWatcher->retrieveCompleteStatus($dbname)],
            ['/{dbname}/database-connections', fn (string $dbname) => $this->databaseWatcher->retrieveActiveMaxConnections($dbname)],
            ['/{dbname}/database-processes', fn (string $dbname) => $this->databaseWatcher->retrieveProcessList($dbname)],
            ['/{dbname}/database-threads', fn (string $dbname) => $this->databaseWatcher->retrieveActiveThreads($dbname)],
        ];

        $this->registerJsonRoutes($serverRoutes);
        $this->registerJsonRoutes($appRoutes);
        $this->registerJsonRoutes($databaseRoutes);
    }

    /**
     * @param array<int, array{0: string, 1: callable}> $routes
     */
    private function registerJsonRoutes(array $routes): void
    {
        foreach ($routes as [$path, $handler]) {
            $this->router->get($path, function (...$args) use ($handler) {
                $this->handleJson($handler, $args);
            });
        }
    }

    private function handleJson(callable $handler, array $args = []): void
    {
        try {
            $result = $handler(...$args);
            $this->sendJson($result);
        } catch (HttpException $e) {
            $this->sendJson([
                'error' => $e->getError(),
                'message' => $e->getMessage(),
                'details' => $e->getContext() ?: null,
            ], $e->getHttpStatus());
        } catch (Throwable $e) {
            $this->logger->error('Request failed', ['exception' => $e]);
            $this->sendJson([
                'error' => 'Request error',
                'message' => 'Internal error',
            ], 500);
        }
    }

    private function sendJson(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }
}
