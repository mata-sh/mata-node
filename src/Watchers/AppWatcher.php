<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Mata\Utils\Tools;
use Mata\Watchers\Exceptions\AppWatcherException;
use Mata\Watchers\LogParsers\LogParserFactory;
use Mata\Watchers\LogParsers\LogParserInterface;
use Mata\Watchers\SessionBackends\SessionBackendFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

// use Alerts\LogAlert;
// use Alerts\EmailAlert;

class AppWatcher
{
    private const DEFAULT_CRONJOB_HEARTBEAT_STALE_SECONDS = 86400;
    private const DEFAULT_CRONJOB_HEARTBEAT_TAIL_LINE_LIMIT = 50;
    private const DEFAULT_LOG_SCAN_LINE_LIMIT = 20000;
    private const LOG_FILTER_ALL = '';
    private const LOG_FILTER_ERRORS = 'errors';
    private const LOG_FILTER_WARNINGS = 'warnings';
    private Tools $tools;
    private LoggerInterface $logger;

    private string $rootPath;
    private array $watcherConfig;
    private array $transmitConfig;
    private array $appsConfig;
    private ?string $cachePath;
    private SessionBackendFactory $sessionBackendFactory;

    public function __construct(
        Tools $tools,
        LoggerInterface $logger,
        string $rootPath,
        array $watcherConfig,
        array $transmitConfig,
        ?SessionBackendFactory $sessionBackendFactory = null,
        ?string $cachePath = null
    ) {
        $this->tools = $tools;
        $this->logger = $logger;
        $this->rootPath = $rootPath;
        $this->watcherConfig = $watcherConfig;
        $this->transmitConfig = $transmitConfig;
        $this->cachePath = $cachePath;
        $this->sessionBackendFactory = $sessionBackendFactory ?? new SessionBackendFactory();
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
        $result = [];
        $result[$app] = $this->getAppInfo($app);

        return $result;
    }

    public function getAppsComposerAudit(): array
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $paths = $this->resolveComposerPaths($config);
            if ($paths === null) {
                continue;
            }

            $result[$app] = $this->composerAudit($paths);
        }

        return $result;
    }

    public function getAppsLibraries(): array
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $paths = $this->resolveComposerPaths($config);
            if ($paths !== null && !empty($paths['lock'])) {
                $result[$app] = $this->composerLibrariesToArray($paths['lock']);
            }
        }

        return $result;
    }

    public function getAppLibraries(string $app): array
    {
        $app = $this->requireAppKey($app);

        $paths = $this->resolveComposerPaths($this->watcherConfig[$app]);
        if ($paths === null || empty($paths['lock'])) {
            throw new AppWatcherException(
                message: 'Composer configuration not found',
                error: 'Composer configuration not found',
                httpStatus: 404,
                context: ['app' => $app]
            );
        }

        return $this->composerLibrariesToArray($paths['lock']);
    }

    public function getAppReadme(string $app): array
    {
        $app = $this->requireAppKey($app);
        $readmeFile = $this->watcherConfig[$app]['readme'] ?? $this->watcherConfig[$app]['path'] . '/README.md';

        try {
            $result[$app] = $this->readmeToString($this->rootPath . '/' . $readmeFile);
        } catch (RuntimeException $e) {
            throw new AppWatcherException(
                message: $e->getMessage(),
                error: 'Readme not found',
                httpStatus: 404,
                context: ['app' => $app, 'readme' => $readmeFile],
                previous: $e
            );
        }

        return $result;
    }

    public function getAppInfo(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->ensureAppConfigLoaded($app);

        $status = $this->getLogStats($app);

        return [
            'path' => $this->watcherConfig[$app]['path'],
            'title' => $this->watcherConfig[$app]['title'],
            'url' => $this->watcherConfig[$app]['url'],
            'ping' => $this->watcherConfig[$app]['ping'] ?? 'on',
            'description' => $this->watcherConfig[$app]['description'] ?? '',
            'environment' => $this->appsConfig[$app]['APP_ENV'],
            'maintenance' => $this->appsConfig[$app]['MAINTENANCE_ENABLED'],
            'status' => $status,
            'session' => $this->getSessionMetadata($app),
            'cronjobs' => $this->getCronjobSummary($app),
            'logs' => $this->getAppLogs($app),
            'configs' => $this->watcherConfig[$app]['configs'],
            'display_errors' => $this->appsConfig[$app]['DISPLAY_ERRORS'],
            'log_level' => $this->appsConfig[$app]['LOG_HELPER_IMPORTANT'],
            'log_helper' => $this->appsConfig[$app]['LOG_HELPER'],
            'alarms_enabled' => $this->watcherConfig[$app]['alarms_enabled'] ?? '',
        ];
    }

    public function getAppLogStatus(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->ensureAppConfigLoaded($app);

        return $this->getLogFileStatus($app);
    }

    public function getAppLogs(string $app): array
    {
        $app = $this->requireAppKey($app);
        $logs = [];
        foreach ($this->getConfiguredLogPaths($app) as $log) {
            $filePath = $this->rootPath . DIRECTORY_SEPARATOR . $log;
            if (is_dir($filePath)) {
                foreach (glob($filePath . DIRECTORY_SEPARATOR . '*.log') as $logFile) {
                    $relativePath = str_replace($this->rootPath . DIRECTORY_SEPARATOR, '', $logFile);
                    $logs[$relativePath] = filesize($logFile) / 1024;
                }

                continue;
            }
            if (file_exists($filePath)) {
                $logs[$log] = filesize($filePath) / 1024;
            }
        }

        return $logs;
    }

    public function getAppSessions(string $app): array
    {
        $app = $this->requireAppKey($app);

        return $this->getSessionMetadata($app);
    }

    public function getAppCronjobs(string $app): array
    {
        $app = $this->requireAppKey($app);

        return $this->buildCronjobEntries($app, includeContent: true);
    }

    public function loadAppConfig(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->ensureAppConfigLoaded($app);

        return $this->appsConfig[$app];
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

    private function ensureAppConfigLoaded(string $app): void
    {
        if (isset($this->appsConfig[$app])) {
            return;
        }

        $result = [];
        $config = [];
        foreach ($this->watcherConfig[$app]['configs'] ?? [] as $cfg) {
            // Get Config Contents
            $filePath = $this->rootPath . DIRECTORY_SEPARATOR . $cfg;
            if (file_exists($filePath)) {
                $config = array_merge($config, $this->tools->loadEnv($this->rootPath . DIRECTORY_SEPARATOR . $cfg));
            }
        }

        foreach ($this->transmitConfig['transmit_config'] ?? [] as $transmit) {
            $result[$transmit] = $config[$transmit] ?? '';
        }
        $this->appsConfig[$app] = $result;
    }

    private function getSessionMetadata(string $app): array
    {
        $sessionConfig = $this->watcherConfig[$app]['sessions'] ?? [];
        if (!is_array($sessionConfig)) {
            $sessionConfig = [];
        }

        $backend = $this->sessionBackendFactory->create($sessionConfig);
        if ($sessionConfig !== []) {
            $sessionConfig['_app'] = $app;
        }

        return $backend->getMetadata($sessionConfig, $this->rootPath, $this->cachePath);
    }

    public function getLogEntries(string $app, string $filter = '', ?int $limit = null): array
    {
        $app = $this->requireAppKey($app);
        $this->ensureAppConfigLoaded($app);
        $limit = max(1, (int) ($limit ?? $this->transmitConfig['errorlog_readout_limit'] ?? 50));
        $scanLineLimit = max(1, (int) ($this->transmitConfig['errorlog_scan_line_limit'] ?? self::DEFAULT_LOG_SCAN_LINE_LIMIT));

        if (!$this->isValidLogFilter($filter)) {
            $this->logger->warning('Invalid log filter requested', [
                'app' => $app,
                'filter' => $filter,
            ]);

            return [];
        }

        $logs = $this->watcherConfig[$app]['logs'] ?? [];
        if (empty($logs)) {
            return [];
        }

        $entries = [];
        foreach ($logs as $logGroup) {
            $paths = [];
            $type = '';

            // app config supports an array of paths and optionally porser-types or a single path
            if (is_string($logGroup)) {
                $paths = [$logGroup];
            } elseif (is_array($logGroup)) {
                $type = $logGroup['type'] ?? '';
                $paths = $logGroup['paths'] ?? [];
            }

            if (empty($paths)) {
                continue;
            }

            foreach ($paths as $logPath) {
                if (!is_string($logPath) || $logPath === '') {
                    continue;
                }

                $logFile = $this->rootPath . '/' . $logPath;

                if (!is_readable($logFile)) {
                    $this->logger->warning('Log file not found or unreadable', [
                        'app' => $app,
                        'log' => $logFile,
                    ]);
                    $entries[$logPath] = [];

                    continue;
                }

                $parser = $this->getLogParser($type, $logFile);
                $lines = $this->tailLogLines($logFile, $scanLineLimit);
                $parsedEntries = $parser->parseLines($lines);
                $parsedEntries = $this->filterLogEntries($parsedEntries, $filter);
                $entries[$logPath] = array_slice($parsedEntries, -$limit);
            }
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function getConfiguredLogPaths(string $app): array
    {
        $logs = $this->watcherConfig[$app]['logs'] ?? [];
        if (!is_array($logs)) {
            return [];
        }

        $paths = [];
        foreach ($logs as $logGroup) {
            if (is_string($logGroup) && $logGroup !== '') {
                $paths[] = $logGroup;

                continue;
            }

            if (!is_array($logGroup)) {
                continue;
            }

            foreach ($logGroup['paths'] ?? [] as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * @return array{total: int, working: int, stale: int, missing: int}
     */
    private function getCronjobSummary(string $app): array
    {
        $summary = [
            'total' => 0,
            'working' => 0,
            'stale' => 0,
            'missing' => 0,
        ];

        foreach ($this->buildCronjobEntries($app, includeContent: false) as $entry) {
            ++$summary['total'];
            if ($entry['exists'] !== true) {
                ++$summary['missing'];

                continue;
            }

            if ($entry['stale'] === true) {
                ++$summary['stale'];

                continue;
            }

            ++$summary['working'];
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCronjobEntries(string $app, bool $includeContent): array
    {
        $entries = [];
        $maxAgeSeconds = $this->resolveCronjobHeartbeatStaleSeconds($app);
        $tailLineLimit = $this->resolveCronjobHeartbeatTailLineLimit($app);

        foreach ($this->getCronjobHeartbeatPaths($app) as $index => $heartbeatPath) {
            $file = $this->rootPath . DIRECTORY_SEPARATOR . $heartbeatPath;
            $exists = is_readable($file) && is_file($file);
            $mtime = $exists ? filemtime($file) : false;
            $ageSeconds = $mtime === false ? null : max(0, time() - $mtime);
            $size = $exists ? filesize($file) : false;
            $entry = [
                'index' => $index,
                'path' => $heartbeatPath,
                'exists' => $exists,
                'mtime' => $mtime === false ? null : date('Y-m-d H:i:s', $mtime),
                'age_seconds' => $ageSeconds,
                'size' => $size === false ? 0 : (int) $size,
                'stale' => $exists && $ageSeconds !== null && $ageSeconds > $maxAgeSeconds,
            ];

            if ($includeContent) {
                $entry['content_tail'] = $exists ? $this->tailLogLines($file, $tailLineLimit) : [];
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function getCronjobHeartbeatPaths(string $app): array
    {
        $configured = $this->watcherConfig[$app]['cronjob-heartbeat'] ?? [];
        if (is_string($configured)) {
            return $configured === '' ? [] : [$configured];
        }

        if (!is_array($configured)) {
            return [];
        }

        $paths = [];
        foreach ($configured as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function resolveCronjobHeartbeatStaleSeconds(string $app): int
    {
        $configured = $this->watcherConfig[$app]['cronjob-heartbeat-stale-seconds'] ?? self::DEFAULT_CRONJOB_HEARTBEAT_STALE_SECONDS;
        if (!is_numeric($configured)) {
            return self::DEFAULT_CRONJOB_HEARTBEAT_STALE_SECONDS;
        }

        return max(1, (int) $configured);
    }

    private function resolveCronjobHeartbeatTailLineLimit(string $app): int
    {
        $configured = $this->watcherConfig[$app]['cronjob-heartbeat-tail-line-limit'] ?? self::DEFAULT_CRONJOB_HEARTBEAT_TAIL_LINE_LIMIT;
        if (!is_numeric($configured)) {
            return self::DEFAULT_CRONJOB_HEARTBEAT_TAIL_LINE_LIMIT;
        }

        return max(1, (int) $configured);
    }

    private function getLogFileStatus(string $app): array
    {
        $logs = $this->watcherConfig[$app]['logs'] ?? [];
        if (empty($logs)) {
            return [];
        }

        $status = [];
        foreach ($logs as $logGroup) {
            $paths = [];

            if (is_string($logGroup)) {
                $paths = [$logGroup];
            } elseif (is_array($logGroup)) {
                $paths = $logGroup['paths'] ?? [];
            }

            if (empty($paths)) {
                continue;
            }

            foreach ($paths as $logPath) {
                if (!is_string($logPath) || $logPath === '') {
                    continue;
                }

                $logFile = $this->rootPath . '/' . $logPath;
                if (!is_readable($logFile)) {
                    $this->logger->warning('Log file not found or unreadable', [
                        'app' => $app,
                        'log' => $logFile,
                    ]);
                    $status[$logPath] = [
                        'size' => 0,
                        'lines' => 0,
                        'last_modified' => '',
                    ];

                    continue;
                }

                $lastModified = filemtime($logFile);
                $status[$logPath] = [
                    'size' => (int) filesize($logFile),
                    'lines' => $this->countFileLines($logFile),
                    'last_modified' => $lastModified ? date('Y-m-d H:i:s', $lastModified) : '',
                ];
            }
        }

        return $status;
    }

    private function isValidLogFilter(string $filter): bool
    {
        return in_array($filter, [
            self::LOG_FILTER_ALL,
            self::LOG_FILTER_ERRORS,
            self::LOG_FILTER_WARNINGS,
        ], true);
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<int,array<string,mixed>>
     */
    private function filterLogEntries(array $entries, string $filter): array
    {
        if ($filter === self::LOG_FILTER_ALL) {
            return $entries;
        }

        return array_values(array_filter($entries, static function (array $entry) use ($filter): bool {
            $type = $entry['type'] ?? '';

            return match ($filter) {
                self::LOG_FILTER_ERRORS => $type === 'Error',
                self::LOG_FILTER_WARNINGS => $type === 'Warning',
                default => false,
            };
        }));
    }

    /**
     * @return array<int,string>
     */
    private function tailLogLines(string $logFile, int $maxLines): array
    {
        $output = shell_exec('tail -n ' . max(1, $maxLines) . ' ' . escapeshellarg($logFile));

        return $output !== null && trim($output) !== '' ? explode("\n", trim($output)) : [];
    }

    private function countFileLines(string $logFile): int
    {
        $output = shell_exec('wc -l < ' . escapeshellarg($logFile));

        return $output === null ? 0 : (int) trim($output);
    }

    public function getLogStats(string $app): array
    {
        $app = $this->requireAppKey($app);
        $this->ensureAppConfigLoaded($app);

        $logs = $this->watcherConfig[$app]['logs'] ?? [];
        $totals = [
            'fatal' => 0,
            'warnings' => 0,
            'total' => 0,
        ];

        if (empty($logs)) {
            return $totals;
        }

        foreach ($logs as $logGroup) {
            $paths = [];
            $type = '';

            if (is_string($logGroup)) {
                $paths = [$logGroup];
            } elseif (is_array($logGroup)) {
                $type = $logGroup['type'] ?? '';
                $paths = $logGroup['paths'] ?? [];
            }

            if (empty($paths)) {
                continue;
            }

            foreach ($paths as $logPath) {
                if (!is_string($logPath) || $logPath === '') {
                    continue;
                }

                $logFile = $this->rootPath . '/' . $logPath;
                if (!is_readable($logFile)) {
                    $this->logger->warning('Log file not found or unreadable', [
                        'app' => $app,
                        'log' => $logFile,
                    ]);

                    continue;
                }

                $parser = $this->getLogParser($type, $logFile);

                $stats = $parser->analyzeLog($logFile);
                $totals['fatal'] += (int) ($stats['fatal'] ?? 0);
                $totals['warnings'] += (int) ($stats['warnings'] ?? 0);
                $totals['total'] += (int) ($stats['total'] ?? 0);
            }
        }

        return $totals;
    }

    private function isComposerAutoEnabled(mixed $composerConfig): bool
    {
        if ($composerConfig === true) {
            return true;
        }

        if (is_string($composerConfig)) {
            return $this->tools->isTruthy($composerConfig);
        }

        return false;
    }

    private function resolveComposerPaths(array $config): ?array
    {
        $binPath = null;
        $jsonPath = null;
        $lockPath = null;

        // composer: [json: path, lock: path]
        $composerConfig = $config['composer'] ?? null;
        if (is_array($composerConfig)) {
            $jsonPath = $composerConfig['json'] ?? null;
            $lockPath = $composerConfig['lock'] ?? null;
            $binPath = $composerConfig['bin'] ?? null;
        }

        // try to auto-detect composer.json and composer.lock
        if ($jsonPath === null || $lockPath === null) {
            $autoEnabled = $this->isComposerAutoEnabled($composerConfig);
            if (!$autoEnabled) {
                return null;
            }

            $basePath = $config['path'] ?? null;
            if (!$basePath) {
                return null;
            }

            $jsonPath ??= rtrim($basePath, '/') . '/composer.json';
            $lockPath ??= rtrim($basePath, '/') . '/composer.lock';
        }

        if ($jsonPath === null || $lockPath === null) {
            return null;
        }

        return [
            'json' => $jsonPath ? $this->tools->normalizePath($jsonPath, $this->rootPath) : null,
            'lock' => $lockPath ? $this->tools->normalizePath($lockPath, $this->rootPath) : null,
            'bin' => $binPath ? $this->tools->normalizePath($binPath, $this->rootPath) : null,
        ];
    }

    private function composerAudit(array $paths): array
    {
        $jsonPath = $paths['json'] ?? null;
        $lockPath = $paths['lock'] ?? null;
        $binPath = $paths['bin'] ?? null;

        if (!$jsonPath || !is_readable($jsonPath)) {
            return [
                'status' => 'error',
                'message' => "composer.json not found or unreadable: {$jsonPath}",
            ];
        }

        if (!$lockPath || !is_readable($lockPath)) {
            return [
                'status' => 'error',
                'message' => "composer.lock not found or unreadable: {$lockPath}",
            ];
        }

        $workingDir = dirname($jsonPath);
        $composerCmd = $this->buildComposerCommand($binPath);
        $cmd = $composerCmd . ' audit --format=json --no-ansi --no-interaction --working-dir=' . escapeshellarg($workingDir);

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return [
                'status' => 'error',
                'message' => 'Failed to start composer audit process',
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = trim($stdout);
        $stderr = trim($stderr);
        $data = $stdout !== '' ? json_decode($stdout, true) : null;

        if (is_array($data)) {
            $result = [
                'status' => 'ok',
                'exit_code' => $exitCode,
                'audit' => $data,
            ];

            if ($stderr !== '') {
                $result['stderr'] = $stderr;
            }

            return $result;
        }

        return [
            'status' => 'error',
            'exit_code' => $exitCode,
            'message' => 'composer audit did not return valid JSON',
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function buildComposerCommand(?string $binPath): string
    {
        if (!$binPath) {
            return 'composer';
        }

        $normalized = strtolower($binPath);
        if (str_ends_with($normalized, '.phar')) {
            return 'php ' . escapeshellarg($binPath);
        }

        return escapeshellarg($binPath);
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

        // # all installed packages, JSON
        // composer show --format=json              > /tmp/all.json

        // # only root-level (direct) requirements, JSON
        // composer show --direct --format=json     > /tmp/direct.json

        if (!is_readable($lockFile)) {
            throw new RuntimeException("composer.lock not found or unreadable: {$lockFile}");
        }

        $data = json_decode(file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);

        $rows = array_map(static fn (array $p) => [
            'name' => $p['name'] ?? '',
            'version' => $p['version'] ?? '',
            'type' => $p['type'] ?? '',
            'description' => $p['description'] ?? '',
        ], $packages);

        return $rows;
    }

    public function readmeToString(string $readmeFile): string
    {

        if (!is_readable($readmeFile)) {
            throw new RuntimeException("readme.md not found or unreadable: {$readmeFile}");
        }

        return file_get_contents($readmeFile);
    }

    private function getLogParser(string $type, string $logFile): LogParserInterface
    {
        if ($type === '') {
            return LogParserFactory::detectFormat($logFile, $this->logger);
        }

        $parser = LogParserFactory::getParser($type, $this->logger);
        if ($parser !== null) {
            return $parser;
        }

        $this->logger->warning('Invalid log parser type configured, using auto-detected parser', [
            'type' => $type,
            'log' => $logFile,
        ]);

        return LogParserFactory::detectFormat($logFile, $this->logger);
    }
}
