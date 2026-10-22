<?php

declare(strict_types=1);

use Bramus\Router\Router;
use Mata\Config\DatabasesConfigParser;
use Mata\Config\LogConfigurator;
use Mata\Config\StartupConfigException;
use Mata\Config\StartupConfigInventory;
use Mata\Config\StartupConfigManager;
use Mata\Config\StartupFailureReason;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Factory\NonceStoreFactory;
use Mata\Factory\RateLimiterFactory;
use Mata\Http\BootFailureResponder;
use Mata\Http\RouterSetup;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Services\FileCache;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\DatabaseWatcher;
use Mata\Watchers\ServerWatcher;

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

    $configDir = $paths->appPath . '/config';
    $startupConfig = (new StartupConfigManager(
        configDir: $configDir,
        exampleDir: $configDir . '/example',
        envFiles: StartupConfigInventory::ENV_FILES,
        jsonFiles: StartupConfigInventory::JSON_FILES,
        logger: $log,
    ))->initialize();

    $envConfig = $startupConfig->envConfig;
    $paths = $paths->withOverrides($envConfig->logging->path, $envConfig->environment->tempDir);
    $paths->ensureDirectories();

    ini_set('error_log', $paths->logsPath . '/php-error.log');
    $log = $logConfigurator->createConfiguredLogger($envConfig->logging, $paths, 'app');

    $watcherConfig = $startupConfig->jsonConfig('apps.json');
    $databaseMonitoring = (new DatabasesConfigParser())->parse($startupConfig->jsonConfig('databases.json'));
    $transmitConfig = $startupConfig->jsonConfig('transmit.json');

    $tools = new Tools();
    $auth = new Auth($envConfig->auth, NonceStoreFactory::create($paths->cachePath));
    $rateLimiter = RateLimiterFactory::create($envConfig->rateLimiting, $log);
    $app = new App($auth, $envConfig, $paths, $rateLimiter);
    $app->setBaseUrl($envConfig->environment->domain);

    $fileCache = new FileCache($paths->cachePath);
    $commandRunner = new NativeCommandRunner();
    $appWatcher = new AppWatcher(
        $tools,
        $log,
        $paths->rootPath,
        $watcherConfig,
        $transmitConfig,
        fileCache: $fileCache,
        commandRunner: $commandRunner,
        composerHome: $paths->cachePath . '/composer',
        composerBin: $envConfig->composer->bin,
        composerPhpBin: $envConfig->composer->phpBin,
    );
    $serverWatcher = new ServerWatcher($paths->rootPath, $fileCache, $transmitConfig, $commandRunner);
    $databaseWatcher = new DatabaseWatcher($databaseMonitoring);

    $router = new Router();
    $routerSetup = new RouterSetup(
        $router,
        $auth,
        $log,
        $paths,
        $appWatcher,
        $serverWatcher,
        $databaseWatcher,
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
