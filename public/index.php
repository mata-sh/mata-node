<?php

declare(strict_types=1);

use Bramus\Router\Router;
use Mata\Config\EnvConfigFatalException;
use Mata\Config\EnvConfigManager;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Factory\RateLimiterFactory;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\DatabaseWatcher;
use Mata\Watchers\ServerWatcher;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

// ************************************************************************************************
// Composer
// ************************************************************************************************
require_once '../vendor/autoload.php';

// ************************************************************************************************
// Path & Tools Initialization
// ************************************************************************************************
$paths = new PathResolver(__FILE__);
$tools = new Tools($paths->appPath);

// ************************************************************************************************
// Error Reporting & Logging
// ************************************************************************************************
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $paths->logsPath . '/phperror.log');

// ************************************************************************************************
// Monolog
// ************************************************************************************************
$dateFormat = 'Y-m-d H:i:s';
$output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
$formatter = new LineFormatter($output, $dateFormat, true, true);
$handler = new StreamHandler($paths->logsPath . '/app.log', Level::Debug);
$handler->setFormatter($formatter);
$log = new Logger('app');
$log->pushHandler($handler);

// ************************************************************************************************
// Config Initialization
// ************************************************************************************************
try {
    $initResult = (new EnvConfigManager(
        configDir: $paths->appPath . '/config',
        exampleDir: $paths->appPath . '/config/example',
        requiredFiles: [
            'config.env',
            'mail.config.env',
        ],
        logger: $log
    ))->initialize();

    $appConfig = $initResult->config;
    $validationWarnings = $initResult->warnings;

} catch (EnvConfigFatalException $e) {
    $log->critical($e->getMessage(), ['context' => $e->getContext()]);

    exit(1);
} catch (Throwable $e) {
    $log->critical('Application Initialization failed! ' . $e->getMessage(), ['exception' => $e]);

    exit('Application initialization failed!');
}

$environment = $appConfig->environment->env;

// ************************************************************************************************
// Authentication Setup
// ************************************************************************************************
$auth = new Auth($appConfig->auth);

// ************************************************************************************************
// Rate Limiter Setup
// ************************************************************************************************
$rateLimiterService = RateLimiterFactory::create($appConfig->rateLimiting, $log);
$app = new App($auth, $appConfig, $paths, $rateLimiterService);
$app->setBaseUrl($appConfig->environment->domain);

// ************************************************************************************************
// Initialize Mailer
// ************************************************************************************************
$transport = Transport::fromDsn($appConfig->mail->dsn);
$mailer = new Mailer($transport);

$mailCfg = [
    'from_email' => $appConfig->mail->fromEmail,
    'from_name' => $appConfig->mail->fromName,
    'recipients' => $appConfig->mail->alertRecipients,
];

$tools = new Tools();

$watcherConfig = json_decode(file_get_contents($paths->appPath . '/config/apps.json'), true);
$databasesConfig = json_decode(file_get_contents($paths->appPath . '/config/databases.json'), true);
$transmitConfig = json_decode(file_get_contents($paths->appPath . '/config/transmit.json'), true);

$appWatcher = new AppWatcher($tools, $log, $paths->rootPath, $watcherConfig, $transmitConfig);
$serverWatcher = new ServerWatcher($paths->rootPath, $paths->cachePath, $transmitConfig);
$databaseWatcher = new DatabaseWatcher($databasesConfig);

$router = new Router();

// ************************************************************************************************
// Public Routes (No Authentication Required)
// ************************************************************************************************
// Health check endpoint for Docker health checks and monitoring
$router->get('/health', function () use ($log, $paths) {
    // Basic health check - verify critical paths are accessible
    $healthy = true;
    $checks = [];

    // Check if logs directory is writable
    $checks['logs_writable'] = is_writable($paths->logsPath);
    if (!$checks['logs_writable']) {
        $healthy = false;
    }

    // Check if cache directory is writable
    $checks['cache_writable'] = is_writable($paths->cachePath);
    if (!$checks['cache_writable']) {
        $healthy = false;
    }

    // Check if config files exist
    $checks['config_exists'] = file_exists($paths->appPath . '/config/config.env');
    if (!$checks['config_exists']) {
        $healthy = false;
    }

    $response = [
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'checks' => $checks,
    ];

    http_response_code($healthy ? 200 : 503);
    header('Content-Type: application/json');
    echo json_encode($response);
});

// ************************************************************************************************
// Layered Security Middleware
// ************************************************************************************************
// Layer 1: Global rate limiting (prevents DoS attacks)
// Layer 2: Authentication validation (HMAC signature)
// Layer 3: Failed auth rate limiting (prevents brute force)
// Note: Health check endpoint (/health) is excluded from authentication
$router->before('GET|POST', '/(?!health$).*', function () use ($auth, $log, $rateLimiterService) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Layer 1: Check general API rate limit
    if ($rateLimiterService?->isRateLimited('api', $ip)) {
        $status = $rateLimiterService->check('api', $ip);
        $log->warning('Rate limit exceeded', [
            'group' => 'api',
            'ip' => $ip,
            'limit' => $status['limit'],
            'current' => $status['current'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);
        http_response_code(429);
        echo json_encode([
            'error' => 'Rate limit exceeded',
            'limit' => $status['limit'],
            'reset_at' => $status['reset_at'],
        ]);

        exit;
    }

    // Layer 2: Validate authentication
    $authResult = $auth->validateHmacAuth();

    if (!$authResult['success']) {
        // Layer 3: Track failed auth attempts with stricter limits
        if ($rateLimiterService?->isRateLimited('auth_failed', $ip)) {
            $status = $rateLimiterService->check('auth_failed', $ip);
            $log->warning('Failed auth rate limit exceeded', [
                'ip' => $ip,
                'limit' => $status['limit'],
                'current' => $status['current'],
            ]);
        }

        $log->warning('Authentication failed', [
            'error' => $authResult['error'],
            'ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);

        http_response_code(401);
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => $authResult['error'],
        ]);

        exit;
    }

    // Store authenticated server ID for downstream use
    $_SERVER['AUTHENTICATED_SERVER_ID'] = $authResult['serverId'];
});

// ************************************************************************************************
// Router Setup
// ************************************************************************************************
$router->get('/server-data', function () use ($serverWatcher) {
    echo $serverWatcher->getServerData();
});

$router->get('/server-php', function () use ($serverWatcher) {
    echo $serverWatcher->getPhpProcesses();
});

$router->get('/apps', function () use ($appWatcher) {
    echo $appWatcher->getApps();
});

$router->get('/apps/libraries', function () use ($appWatcher) {
    echo $appWatcher->getAppsLibraries();
});

$router->get('/apps/composer-audit', function () use ($appWatcher) {
    echo $appWatcher->getAppsComposerAudit();
});

$router->get('/{app}/readme', function ($app) use ($appWatcher) {
    echo $appWatcher->getAppReadme($app);
});

$router->get('/{app}/session-count', function ($app) use ($appWatcher) {
    $appWatcher->loadAppConfig($app);
    echo $appWatcher->getSessionCount(app: $app);
});

$router->get('/{app}/session-count/{filter}', function ($app, $filter) use ($appWatcher) {
    $appWatcher->loadAppConfig($app);
    echo $appWatcher->getSessionCount(app: $app, maxAgeSeconds: (int) $filter);
});

$router->get('/{app}/logs', function ($app) use ($appWatcher) {
    echo $appWatcher->getLogEntries(app: $app);
});

$router->get('/{app}/logs-status', function ($app) use ($appWatcher) {
    echo $appWatcher->getAppLogStatus($app);
});

$router->get('/{app}/app-config', function ($app) use ($appWatcher) {
    echo $appWatcher->loadAppConfig($app);
});

$router->get('/{app}/libraries', function ($app) use ($appWatcher) {
    echo $appWatcher->getAppLibraries($app);
});

// Database Watcher
$router->get('/server-databases', function () use ($databaseWatcher) {
    echo json_encode($databaseWatcher->getAvailableDatabases());
});

$router->get('/databases-status', function () use ($databaseWatcher) {
    echo json_encode($databaseWatcher->retrieveAllDatabasesStatus());
});

$router->get('/{dbname}/database-status', function ($dbname) use ($databaseWatcher) {
    echo json_encode($databaseWatcher->retrieveCompleteStatus($dbname));
});

$router->get('/{dbname}/database-connections', function ($dbname) use ($databaseWatcher) {
    echo $databaseWatcher->retrieveActiveMaxConnections($dbname);
});

$router->get('/{dbname}/database-processes', function ($dbname) use ($databaseWatcher) {
    echo json_encode($databaseWatcher->retrieveProcessList($dbname));
});

$router->get('/{dbname}/database-threads', function ($dbname) use ($databaseWatcher) {
    echo $databaseWatcher->retrieveActiveThreads($dbname);
});

$router->run();
