<?php

declare(strict_types=1);

namespace Mata\Http;

use Bramus\Router\Router;
use Mata\Config\Dto\GeneralConfig;
use Mata\Core\Auth;
use Mata\Security\IpAllowlist;
use Mata\Services\RateLimiter;
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
        private GeneralConfig $generalConfig,
        private ?RateLimiter $rateLimiter = null,
        private ClientIpResolver $clientIpResolver = new ClientIpResolver(),
        private IpAllowlist $ipAllowlist = new IpAllowlist(),
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
                'maintenance_mode' => $this->generalConfig->maintenanceModeEnabled,
                'checks' => $checks,
            ];

            $this->sendJson($response, $healthy ? 200 : 503);
        });
    }

    private function setupAuthAndRateLimiting(): void
    {
        $this->router->before('GET|POST', '/(?!health$).*', function () {
            if ($this->generalConfig->maintenanceModeEnabled) {
                $this->logger->notice('Maintenance mode denied request', [
                    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                header('Retry-After: 300');
                $this->sendJson([
                    'error' => 'Service unavailable',
                    'message' => 'Maintenance mode enabled',
                ], 503);

                exit;
            }

            $ipResolution = $this->clientIpResolver->resolve($_SERVER, $this->generalConfig->trustedProxies);
            $ip = $ipResolution->clientIp;

            if ($this->generalConfig->ipWhitelistEnabled && !$this->ipAllowlist->isAllowed($ip, $this->generalConfig->allowedIps)) {
                $this->logger->warning('IP allowlist denied request', [
                    'client_ip' => $ipResolution->clientIp,
                    'remote_addr' => $ipResolution->remoteAddr,
                    'x_forwarded_for' => $ipResolution->forwardedFor,
                    'trusted_proxy' => $ipResolution->usedTrustedProxy,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ]);

                $this->sendJson([
                    'error' => 'Forbidden',
                ], 403);

                exit;
            }

            if ($this->rateLimiter?->isRateLimited('api', $ip)) {
                $status = $this->rateLimiter->check('api', $ip);
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
                if ($this->rateLimiter?->isRateLimited('auth_failed', $ip)) {
                    $status = $this->rateLimiter->check('auth_failed', $ip);
                    $this->logger->warning('Failed auth rate limit exceeded', [
                        'ip' => $ip,
                        'limit' => $status['limit'],
                        'current' => $status['current'],
                    ]);
                }

                $this->logger->warning('Authentication failed', [
                    'error' => $authResult['error'],
                    'ip' => $ip,
                    'remote_addr' => $ipResolution->remoteAddr,
                    'trusted_proxy' => $ipResolution->usedTrustedProxy,
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
            ['/server/data', fn () => $this->serverWatcher->getData()],
            ['/server/info', fn () => $this->serverWatcher->getInfo()],
            ['/server/php', fn () => $this->serverWatcher->getPhpProcesses()],
            ['/server/cronjobs', fn () => $this->serverWatcher->getCronjobs()],
        ];

        $appRoutes = [
            ['/apps', fn () => $this->appWatcher->getApps()],
            ['/apps/libraries', fn () => $this->appWatcher->getAppsLibraries()],
            ['/apps/composer-audit', fn () => $this->appWatcher->getAppsComposerAudit()],

            ['/{app}/readme', fn (string $app) => $this->appWatcher->getAppReadme($app)],
            ['/{app}/sessions', fn (string $app) => $this->appWatcher->getAppSessions($app)],
            ['/{app}/logs', fn (string $app) => $this->appWatcher->getAppLogEntries(
                app: $app,
                filter: is_string($_GET['filter'] ?? null) ? $_GET['filter'] : '',
                limit: isset($_GET['limit']) && is_scalar($_GET['limit']) ? (int) $_GET['limit'] : null,
            )],
            ['/{app}/logs-status', fn (string $app) => $this->appWatcher->getAppLogStatus($app)],
            ['/{app}/libraries', fn (string $app) => $this->appWatcher->getAppLibraries($app)],
            ['/{app}/composer-audit', fn (string $app) => $this->appWatcher->getAppComposerAudit($app)],
        ];

        $databaseRoutes = [
            ['/server/databases', fn () => $this->databaseWatcher->getDatabasesStatus()],
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
