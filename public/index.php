<?php

declare(strict_types=1);

use Bramus\Router\Router;
use Mata\Config\EnvConfigFatalException;
use Mata\Config\EnvConfigManager;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Factory\RateLimiterFactory;
use Mata\Http\RouterSetup;
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
ini_set('error_log', $paths->logsPath . '/php-error.log');

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
$routerSetup = new RouterSetup(
    $router,
    $auth,
    $log,
    $paths,
    $appWatcher,
    $serverWatcher,
    $databaseWatcher,
    $rateLimiterService
);
$routerSetup->setup();
$routerSetup->run();
