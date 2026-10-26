<?php

declare(strict_types=1);

namespace Mata\Http;

use Bramus\Router\Router;
use JsonException;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\StartupConfigInventory;
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
            $ipResolution = $this->clientIpResolver->resolve($_SERVER, $this->generalConfig->trustedProxies);
            $this->logger->debug('Health check HTTP request', $this->requestContext($ipResolution));

            $missingConfigFiles = StartupConfigInventory::findMissingFiles($this->paths->appPath . '/config');
            $checks = [
                'logs_writable' => is_writable($this->paths->logsPath),
                'cache_writable' => is_writable($this->paths->cachePath),
                'config_files_present' => $missingConfigFiles === [],
            ];
            $healthy = !in_array(false, $checks, true);

            $this->sendJson([
                'status' => $healthy ? 'healthy' : 'unhealthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'maintenance_mode' => $this->generalConfig->maintenanceModeEnabled,
                'checks' => $checks,
                'missing_config_files' => $missingConfigFiles,
            ], $healthy ? 200 : 503);
        });
    }

    private function setupAuthAndRateLimiting(): void
    {
        $this->router->before('GET|POST', '/(?!health$).*', function () {
            $ipResolution = $this->clientIpResolver->resolve($_SERVER, $this->generalConfig->trustedProxies);
            $ip = $ipResolution->clientIp;

            if ($this->generalConfig->maintenanceModeEnabled) {
                $this->logger->notice('Maintenance mode denied request', $this->requestContext($ipResolution));

                header('Retry-After: 300');
                $this->sendJson([
                    'error' => 'Service unavailable',
                    'message' => 'Maintenance mode enabled',
                ], 503);

                exit;
            }

            if ($this->generalConfig->ipWhitelistEnabled && !$this->ipAllowlist->isAllowed($ip, $this->generalConfig->allowedIps)) {
                $this->logger->warning('IP allowlist denied request', [
                    ...$this->requestContext($ipResolution),
                    'x_forwarded_for' => $ipResolution->forwardedFor,
                ]);

                $this->sendJson([
                    'error' => 'Forbidden',
                ], 403);

                exit;
            }

            $rateLimitResult = $this->rateLimiter?->consume('api', $ip);
            if ($rateLimitResult !== null && !$rateLimitResult->isAllowed()) {
                $limit = $this->rateLimiter->getLimit('api');
                $retryAt = $rateLimitResult->getRetryAt();
                $this->logger->warning('Rate limit exceeded', [
                    'group' => 'api',
                    'limit' => $limit,
                    'current' => $rateLimitResult->getCurrent(),
                    ...$this->requestContext($ipResolution),
                ]);
                if ($retryAt !== null) {
                    header('Retry-After: ' . max(1, $retryAt - time()));
                }
                $this->sendJson([
                    'error' => 'Rate limit exceeded',
                    'limit' => $limit,
                    'reset_at' => $retryAt,
                ], 429);

                exit;
            }

            // Checked before authenticating, so an IP over the limit cannot keep guessing.
            if ($this->rateLimiter?->isExhausted('auth_failed', $ip)) {
                $this->logger->warning('Failed auth rate limit exceeded', [
                    'limit' => $this->rateLimiter->getLimit('auth_failed'),
                    ...$this->requestContext($ipResolution),
                ]);
                header('Retry-After: ' . $this->rateLimiter->getWindowSeconds('auth_failed'));
                $this->sendJson([
                    'error' => 'Rate limit exceeded',
                    'message' => 'Too many failed authentication attempts',
                ], 429);

                exit;
            }

            $authResult = $this->auth->validateHmacAuth();

            if (!$authResult['success']) {
                $this->rateLimiter?->consume('auth_failed', $ip);

                $this->logger->warning('Authentication failed', [
                    'error' => $authResult['error'],
                    ...$this->requestContext($ipResolution),
                ]);

                $this->sendJson([
                    'error' => 'Unauthorized',
                    'message' => $authResult['error'],
                ], 401);

                exit;
            }

            $_SERVER['AUTHENTICATED_SERVER_ID'] = $authResult['serverId'];

            $this->logger->debug('Authorized HTTP request', [
                'server_id' => $authResult['serverId'],
                ...$this->requestContext($ipResolution),
            ]);
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
            ['/{app}/logs', function (string $app): array {
                $limit = isset($_GET['limit']) && is_scalar($_GET['limit']) ? (int) $_GET['limit'] : null;

                return [
                    'grouped' => true,
                    'entries' => $this->appWatcher->getAppLogEntries(
                        app: $app,
                        filter: is_string($_GET['filter'] ?? null) ? $_GET['filter'] : '',
                        limit: $limit,
                    ),
                    'readout_limit' => $this->appWatcher->getAppLogReadoutLimit($limit),
                ];
            }],
            ['/{app}/logs-status', fn (string $app) => $this->appWatcher->getAppLogStatus($app)],
            ['/{app}/libraries', fn (string $app) => $this->appWatcher->getAppLibraries($app)],
            ['/{app}/composer-audit', fn (string $app) => $this->appWatcher->getAppComposerAudit($app)],
        ];

        $databaseRoutes = [
            ['/databases/overview', fn () => $this->databaseWatcher->getOverview()],
            ['/databases/processes', fn () => $this->databaseWatcher->getProcesses($_GET)],
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

    /**
     * Request fields shared by the access log lines.
     *
     * @return array<string, mixed>
     */
    private function requestContext(ClientIpResolution $ipResolution): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'client_ip' => $ipResolution->clientIp,
            'remote_addr' => $ipResolution->remoteAddr,
            'trusted_proxy' => $ipResolution->usedTrustedProxy,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];
    }

    private function sendJson(mixed $payload, int $status = 200): void
    {
        // Monitored logs and READMEs need not be UTF-8; substitute invalid bytes rather than send an empty body.
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            $this->logger->error('Response encoding failed', ['exception' => $e]);
            $status = 500;
            $body = '{"error":"Request error","message":"Internal error"}';
        }

        http_response_code($status);
        header('Content-Type: application/json');
        echo $body;
    }
}
