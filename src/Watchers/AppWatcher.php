<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Services\Command\ProcessCommandRunner;
use Mata\Services\Command\ProcessCommandRunnerInterface;
use Mata\Services\FileCache;
use Mata\Utils\Tools;
use Mata\Watchers\App\AppConfigReader;
use Mata\Watchers\App\ComposerInspector;
use Mata\Watchers\App\CronjobHeartbeatInspector;
use Mata\Watchers\App\LogInspector;
use Mata\Watchers\App\ReadmeReader;
use Mata\Watchers\Exceptions\AppWatcherException;
use Mata\Watchers\SessionBackends\SessionBackendFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

// use Alerts\LogAlert;
// use Alerts\EmailAlert;

class AppWatcher
{
    private LoggerInterface $logger;
    private string $rootPath;
    private array $watcherConfig;
    private SessionBackendFactory $sessionBackendFactory;
    private AppConfigReader $appConfigReader;
    private LogInspector $logInspector;
    private CronjobHeartbeatInspector $cronjobHeartbeatInspector;
    private ComposerInspector $composerInspector;
    private ReadmeReader $readmeReader;

    public function __construct(
        Tools $tools,
        LoggerInterface $logger,
        string $rootPath,
        array $watcherConfig,
        array $transmitConfig,
        ?SessionBackendFactory $sessionBackendFactory = null,
        ?FileCache $fileCache = null,
        ?CommandRunnerInterface $commandRunner = null,
        ?ProcessCommandRunnerInterface $processRunner = null
    ) {
        $this->logger = $logger;
        $this->rootPath = $rootPath;
        $this->watcherConfig = $watcherConfig;

        $processRunner = $processRunner ?? new ProcessCommandRunner();
        $commandRunner = $commandRunner ?? new NativeCommandRunner($processRunner);

        $this->sessionBackendFactory = $sessionBackendFactory ?? new SessionBackendFactory(null, $fileCache);
        $this->appConfigReader = new AppConfigReader($tools, $rootPath, $watcherConfig, $transmitConfig);
        $this->logInspector = new LogInspector($logger, $rootPath, $watcherConfig, $transmitConfig, $commandRunner);
        $this->cronjobHeartbeatInspector = new CronjobHeartbeatInspector($rootPath, $watcherConfig, $commandRunner);
        $this->composerInspector = new ComposerInspector($tools, $rootPath, $processRunner);
        $this->readmeReader = new ReadmeReader($rootPath);
    }

    public function getApps(): array
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $result[$app] = $this->getAppInfo($app);
        }

        return $result;
    }

    public function getApp(string $app): array
    {
        return [$app => $this->getAppInfo($app)];
    }

    public function getAppsComposerAudit(): array
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $paths = $this->composerInspector->resolvePaths($config);
            if ($paths === null) {
                continue;
            }

            $result[$app] = $this->composerInspector->audit($paths);
        }

        return $result;
    }

    public function getAppsLibraries(): array
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $paths = $this->composerInspector->resolvePaths($config);
            if ($paths !== null && !empty($paths['lock'])) {
                $result[$app] = $this->composerInspector->librariesToArray($paths['lock']);
            }
        }

        return $result;
    }

    public function getAppLibraries(string $app): array
    {
        $app = $this->requireAppKey($app);

        $paths = $this->composerInspector->resolvePaths($this->watcherConfig[$app]);
        if ($paths === null || empty($paths['lock'])) {
            throw new AppWatcherException(
                message: 'Composer configuration not found',
                error: 'Composer configuration not found',
                httpStatus: 404,
                context: ['app' => $app]
            );
        }

        return $this->composerInspector->librariesToArray($paths['lock']);
    }

    /**
     * @return array{content: string}
     */
    public function getAppReadme(string $app): array
    {
        $app = $this->requireAppKey($app);
        $readmeFile = $this->watcherConfig[$app]['readme'] ?? $this->watcherConfig[$app]['path'] . '/README.md';

        try {
            return ['content' => $this->readmeReader->read($this->rootPath . '/' . $readmeFile)];
        } catch (RuntimeException $e) {
            throw new AppWatcherException(
                message: $e->getMessage(),
                error: 'Readme not found',
                httpStatus: 404,
                context: ['app' => $app, 'readme' => $readmeFile],
                previous: $e
            );
        }
    }

    public function getAppInfo(string $app): array
    {
        $app = $this->requireAppKey($app);
        $appConfig = $this->watcherConfig[$app];
        $config = $this->appConfigReader->load($app);

        return [
            'path' => $appConfig['path'],
            'title' => $appConfig['title'],
            'url' => $appConfig['url'],
            'ping' => $appConfig['ping'] ?? 'on',
            'description' => $appConfig['description'] ?? '',
            'environment' => $config['APP_ENV'],
            'maintenance' => $config['MAINTENANCE_ENABLED'],
            'status' => $this->logInspector->stats($app),
            'session' => $this->getSessionMetadata($app),
            'cronjobs' => $this->cronjobHeartbeatInspector->summary($app),
            'logs' => $this->logInspector->files($app),
            'configs' => $appConfig['configs'],
            'display_errors' => $config['DISPLAY_ERRORS'],
            'log_level' => $config['LOG_HELPER_IMPORTANT'],
            'log_helper' => $config['LOG_HELPER'],
            'alarms_enabled' => $appConfig['alarms_enabled'] ?? '',
        ];
    }

    public function getAppLogStatus(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->appConfigReader->load($app);

        return $this->logInspector->status($app);
    }

    public function getAppLogs(string $app): array
    {
        $app = $this->requireAppKey($app);

        return $this->logInspector->files($app);
    }

    public function getAppSessions(string $app): array
    {
        $app = $this->requireAppKey($app);

        return $this->getSessionMetadata($app);
    }

    public function getAppCronjobs(string $app): array
    {
        $app = $this->requireAppKey($app);

        return $this->cronjobHeartbeatInspector->entries($app, includeContent: true);
    }

    public function loadAppConfig(string $app): array
    {
        $app = $this->requireAppKey($app);

        return $this->appConfigReader->load($app);
    }

    public function getLogEntries(string $app, string $filter = '', ?int $limit = null): array
    {
        $app = $this->requireAppKey($app);
        $this->appConfigReader->load($app);

        return $this->logInspector->entries($app, $filter, $limit);
    }

    public function getAppLogStats(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->appConfigReader->load($app);

        return $this->logInspector->stats($app);
    }

    /**
     * Return all libraries from a composer.lock.
     *
     * @param string $lockFile Path to composer.lock
     *
     * @return array<int, array{name: string, version: string, type: string, description: string}>
     *
     * @throws RuntimeException If file unreadable or JSON invalid
     */
    public function composerLibrariesToArray(string $lockFile): array
    {
        return $this->composerInspector->librariesToArray($lockFile);
    }

    public function readmeToString(string $readmeFile): string
    {
        return $this->readmeReader->read($readmeFile);
    }

    private function requireAppKey(string $app): string
    {
        $normalized = strtolower($app);
        if (!isset($this->watcherConfig[$normalized])) {
            $this->logger->warning('Unknown app requested', ['app' => $normalized]);

            throw AppWatcherException::notFound($normalized);
        }

        return $normalized;
    }

    private function getSessionMetadata(string $app): array
    {
        $sessionConfig = $this->watcherConfig[$app]['sessions'] ?? [];
        if (!is_array($sessionConfig)) {
            $sessionConfig = [];
        }

        $backend = $this->sessionBackendFactory->create($sessionConfig);

        return $backend->getMetadata($sessionConfig, $this->rootPath, $app);
    }
}
