<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Services\Command\ProcessCommandRunner;
use Mata\Services\Command\ProcessCommandRunnerInterface;
use Mata\Services\FileCache;
use Mata\Utils\Tools;
use Mata\Watchers\App\AppMonitoringSettings;
use Mata\Watchers\App\ComposerInspector;
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
    private LogInspector $logInspector;
    private ComposerInspector $composerInspector;
    private ReadmeReader $readmeReader;

    /**
     * Per-request memo, so each app's `monitoring` block is parsed once even
     * though `getApps()` loops over `getAppInfo()` and the detail guards need it too.
     *
     * @var array<string, AppMonitoringSettings>
     */
    private array $monitoringSettings = [];

    public function __construct(
        Tools $tools,
        LoggerInterface $logger,
        string $rootPath,
        array $watcherConfig,
        array $transmitConfig,
        ?SessionBackendFactory $sessionBackendFactory = null,
        ?FileCache $fileCache = null,
        ?CommandRunnerInterface $commandRunner = null,
        ?ProcessCommandRunnerInterface $processRunner = null,
        ?string $composerHome = null,
        ?string $composerBin = null,
        ?string $composerPhpBin = null,
    ) {
        $this->logger = $logger;
        $this->rootPath = $rootPath;
        $this->watcherConfig = $this->excludeDisabledApps($watcherConfig);

        $processRunner = $processRunner ?? new ProcessCommandRunner();
        $commandRunner = $commandRunner ?? new NativeCommandRunner($processRunner);

        $this->sessionBackendFactory = $sessionBackendFactory ?? new SessionBackendFactory(null, $fileCache);
        $this->logInspector = new LogInspector($logger, $rootPath, $tools, $this->watcherConfig, $transmitConfig, $commandRunner);
        $this->composerInspector = new ComposerInspector(
            $tools,
            $logger,
            $rootPath,
            $processRunner,
            $composerHome,
            $composerBin,
            $composerPhpBin,
        );
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

    /**
     * @return array<string, mixed>
     */
    public function getAppComposerAudit(string $app): array
    {
        $app = $this->requireAppKey($app);

        $paths = $this->composerInspector->resolvePaths($this->watcherConfig[$app]);
        if ($paths === null) {
            throw new AppWatcherException(
                message: 'Composer configuration not found',
                error: 'Composer configuration not found',
                httpStatus: 404,
                context: ['app' => $app]
            );
        }

        return $this->composerInspector->audit($paths);
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
     * @return array{content: string, notice?: array{code: string, message: string}}
     */
    public function getAppReadme(string $app): array
    {
        $app = $this->requireAppKey($app);
        $appConfig = $this->watcherConfig[$app];

        try {
            return ['content' => $this->readmeReader->readFromConfig($appConfig)];
        } catch (RuntimeException) {
            return [
                'content' => '',
                'notice' => [
                    'code' => 'README_NOT_FOUND',
                    'message' => 'README not found',
                ],
            ];
        }
    }

    public function getAppInfo(string $app): array
    {
        $app = $this->requireAppKey($app);
        $appConfig = $this->watcherConfig[$app];
        if (!is_array($appConfig)) {
            $this->invalidAppConfiguration($app, 'config');
        }

        $metadata = $this->normalizeAppMetadata($app, $appConfig);
        $monitoring = $metadata['monitoring'];

        $info = [
            'path' => $metadata['path'],
            'title' => $metadata['title'],
            'url' => $metadata['url'],
            'env' => $metadata['env'],
            'status_url' => $metadata['status_url'],
            'description' => $appConfig['description'] ?? '',
            'composer' => $this->composerInspector->isEnabled($appConfig['composer'] ?? null),
        ];

        // A disabled setting must skip the work, not just hide the result. These
        // three calls are the expensive part of `/apps`.
        if ($monitoring->loganalysis) {
            $info['status'] = $this->logInspector->stats($app);
        }

        if ($monitoring->sessions) {
            $info['session'] = $this->getSessionMetadata($app);
        }

        if ($monitoring->logsize) {
            $info['logs'] = $this->logInspector->files($app);
        }

        $disabled = $monitoring->disabledMap();
        if ($disabled !== []) {
            $info['monitoring'] = $disabled;
        }

        return $info;
    }

    public function getAppLogStatus(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->requireMonitoring($app, 'logsize');

        return $this->logInspector->status($app);
    }

    public function getAppLogs(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->requireMonitoring($app, 'logsize');

        return $this->logInspector->files($app);
    }

    public function getAppSessions(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->requireMonitoring($app, 'sessions');

        return $this->getSessionMetadata($app);
    }

    public function getAppLogEntries(string $app, string $filter = '', ?int $limit = null): array
    {
        $app = $this->requireAppKey($app);
        $this->requireMonitoring($app, 'loganalysis');

        return $this->logInspector->entries($app, $filter, $limit);
    }

    public function getAppLogReadoutLimit(?int $limit = null): int
    {
        return $this->logInspector->readoutLimit($limit);
    }

    public function getAppLogStats(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->requireMonitoring($app, 'loganalysis');

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

    /**
     * @param array<string, mixed> $watcherConfig
     *
     * @return array<string, mixed>
     */
    private function excludeDisabledApps(array $watcherConfig): array
    {
        foreach ($watcherConfig as $app => $config) {
            if (is_array($config) && ($config['disabled'] ?? 'false') === 'true') {
                unset($watcherConfig[$app]);
            }
        }

        return $watcherConfig;
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

    /**
     * @param array<string, mixed> $appConfig
     *
     * @return array{title: string, url: string, path: string, env: string, status_url: ?string, monitoring: AppMonitoringSettings}
     */
    private function normalizeAppMetadata(string $app, array $appConfig): array
    {
        foreach (['title', 'url', 'path', 'env'] as $field) {
            if (!isset($appConfig[$field]) || !is_string($appConfig[$field]) || trim($appConfig[$field]) === '') {
                $this->invalidAppConfiguration($app, $field);
            }
        }

        if (!in_array($appConfig['env'], ['dev', 'test', 'stg', 'prod'], true)) {
            $this->invalidAppConfiguration($app, 'env');
        }

        $statusEndpoint = array_key_exists('status_endpoint', $appConfig)
            ? self::normalizeStatusEndpoint($appConfig['status_endpoint'])
            : ['valid' => true, 'url' => null];
        if (!$statusEndpoint['valid']) {
            $this->invalidAppConfiguration($app, 'status_endpoint');
        }

        $monitoring = AppMonitoringSettings::parse($appConfig['monitoring'] ?? null);
        if ($monitoring['invalid_field'] !== null) {
            $this->invalidAppConfiguration($app, $monitoring['invalid_field']);
        }

        $this->monitoringSettings[$app] = $monitoring['settings'];

        return [
            'title' => $appConfig['title'],
            'url' => $appConfig['url'],
            'path' => $appConfig['path'],
            'env' => $appConfig['env'],
            'status_url' => $statusEndpoint['url'],
            'monitoring' => $monitoring['settings'],
        ];
    }

    /**
     * Refuse a detail route whose monitoring setting is switched off for this app.
     */
    private function requireMonitoring(string $app, string $setting): void
    {
        if (!$this->monitoringFor($app)->isEnabled($setting)) {
            $this->logger->warning('Monitoring disabled for requested app', [
                'app' => $app,
                'setting' => $setting,
            ]);

            throw AppWatcherException::monitoringDisabled($app, $setting);
        }
    }

    /**
     * Memoized monitoring settings for one app.
     *
     * Only the `monitoring` block is validated here; the detail routes never
     * required the rest of the app metadata to be complete.
     */
    private function monitoringFor(string $app): AppMonitoringSettings
    {
        if (isset($this->monitoringSettings[$app])) {
            return $this->monitoringSettings[$app];
        }

        $appConfig = $this->watcherConfig[$app];
        if (!is_array($appConfig)) {
            $this->invalidAppConfiguration($app, 'config');
        }

        $parsed = AppMonitoringSettings::parse($appConfig['monitoring'] ?? null);
        if ($parsed['invalid_field'] !== null) {
            $this->invalidAppConfiguration($app, $parsed['invalid_field']);
        }

        return $this->monitoringSettings[$app] = $parsed['settings'];
    }

    /**
     * @return array{valid: bool, url: ?string}
     */
    private static function normalizeStatusEndpoint(mixed $endpoint): array
    {
        if ($endpoint === '') {
            return ['valid' => true, 'url' => null];
        }

        if (!is_string($endpoint) || trim($endpoint) !== $endpoint || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return ['valid' => false, 'url' => null];
        }

        $parts = parse_url($endpoint);
        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['fragment'])
        ) {
            return ['valid' => false, 'url' => null];
        }

        return ['valid' => true, 'url' => $endpoint];
    }

    private function invalidAppConfiguration(string $app, string $field): never
    {
        $this->logger->error('Invalid application configuration', [
            'app' => $app,
            'field' => $field,
        ]);

        throw new AppWatcherException(
            message: 'Invalid application configuration',
            error: 'Invalid application configuration',
            httpStatus: 500,
        );
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
