<?php

declare(strict_types=1);

use Bramus\Router\Router;
use Mata\Bootstrap\Runtime;
use Mata\Config\LogConfigurator;
use Mata\Config\StartupConfigException;
use Mata\Config\StartupFailureReason;
use Mata\Core\Auth;
use Mata\Factory\NonceStoreFactory;
use Mata\Factory\RateLimiterFactory;
use Mata\Http\BootFailureResponder;
use Mata\Http\RouterSetup;
use Mata\Utils\PathResolver;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$responder = new BootFailureResponder();
$log = null;

try {
    $paths = PathResolver::fromEntryPoint(__FILE__);

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $paths->bootLogPath);

    $logConfigurator = new LogConfigurator();
    $log = $logConfigurator->createBootLogger($paths);

    $runtime = Runtime::boot($paths, $log);
    $envConfig = $runtime->env;
    $paths = $runtime->paths;
    $paths->ensureDirectories();

    ini_set('error_log', $paths->logsPath . '/php-error.log');
    $log = $logConfigurator->createConfiguredLogger($envConfig->logging, $paths, 'app');

    $auth = new Auth($envConfig->auth, NonceStoreFactory::create($paths->cachePath));
    $rateLimiter = RateLimiterFactory::create($envConfig->rateLimiting, $log);

    $router = new Router();
    $routerSetup = new RouterSetup(
        $router,
        $auth,
        $log,
        $paths,
        $runtime->appWatcher($log),
        $runtime->serverWatcher(),
        $runtime->databaseWatcher($log),
        $envConfig->general,
        $rateLimiter,
    );
    $routerSetup->setup();
} catch (StartupConfigException $e) {
    $log->critical($e->getMessage(), ['context' => $e->getContext(), 'exception' => $e]);

    $responder->respond($e->getReason(), $e->getMissingFiles());
} catch (Throwable $e) {
    if ($log !== null) {
        $log->critical('Application startup failed', ['exception' => $e]);
    } else {
        error_log('Application startup failed: ' . $e->getMessage());
    }

    $responder->respond(StartupFailureReason::InitializationFailed);
}

$routerSetup->run();
