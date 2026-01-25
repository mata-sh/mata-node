<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Mata\Utils\Tools;
use Mata\Watchers\LogParsers\LogParserFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

// use Alerts\LogAlert;
// use Alerts\EmailAlert;

class AppWatcher
{
    private Tools $tools;
    private LoggerInterface $logger;

    private string $rootPath;
    private array $watcherConfig;
    private array $transmitConfig;
    private array $appsConfig;
    private array $parserCache = [];

    public function __construct(Tools $tools, LoggerInterface $logger, string $rootPath, array $watcherConfig, array $transmitConfig)
    {
        $this->tools = $tools;
        $this->logger = $logger;
        $this->rootPath = $rootPath;
        $this->watcherConfig = $watcherConfig;
        $this->transmitConfig = $transmitConfig;
    }

    public function getApps()
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $result[$app] = $this->getAppInfo($app);
        }

        return json_encode($result);
    }

    public function getApp($app)
    {
        $result[$app] = $this->getAppInfo($app);

        return json_encode($result);
    }

    public function getAppsComposerAudit(): string
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $paths = $this->resolveComposerPaths($config);
            if ($paths === null) {
                continue;
            }

            $result[$app] = $this->composerAudit($paths);
        }

        return json_encode($result);
    }

    public function getAppsLibrariesJson()
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $paths = $this->resolveComposerPaths($config);
            if ($paths !== null && !empty($paths['lock'])) {
                $result[$app] = $this->composerLibrariesToJson($paths['lock']);
            }
        }

        return $result;
    }

    public function getAppsLibraries()
    {
        $result = [];
        foreach ($this->watcherConfig as $app => $config) {
            $paths = $this->resolveComposerPaths($config);
            if ($paths !== null && !empty($paths['lock'])) {
                $result[$app] = json_decode(
                    $this->composerLibrariesToJson($paths['lock'])
                );
            }
        }

        return json_encode($result);
    }

    public function getAppLibraries(string $app): string
    {
        $app = strtolower($app);
        if (!isset($this->watcherConfig[$app])) {
            return json_encode(['error' => 'Unknown app']);
        }

        $paths = $this->resolveComposerPaths($this->watcherConfig[$app]);
        if ($paths === null || empty($paths['lock'])) {
            return json_encode(['error' => 'Composer configuration not found']);
        }

        return $this->composerLibrariesToJson($paths['lock']);
    }

    public function getAppReadme($app)
    {
        $readmeFile = $this->watcherConfig[$app]['readme'] ?? $this->watcherConfig[$app]['path'] . '/README.md';

        try {
            $result[$app] = json_decode(
                $this->readmeToJson($this->rootPath . '/' . $readmeFile)
            );
        } catch (RuntimeException $e) {
            error_log($e->getMessage());

            return '{}';
        }

        return json_encode($result);
    }

    public function getAppInfo(string $app): array
    {
        $app = strtolower($app);
        $this->loadAppConfig($app);

        $status = $this->getLogStats($app);

        return [
            'path' => $this->watcherConfig[$app]['path'],
            'title' => $this->watcherConfig[$app]['title'],
            'url' => $this->watcherConfig[$app]['url'],
            'ping' => $this->watcherConfig[$app]['ping'] ?? 'on',
            'environment' => $this->appsConfig[$app]['APP_ENV'],
            'maintenance' => $this->appsConfig[$app]['MAINTENANCE_ENABLED'],
            'status' => $status,
            'session_count_last_hour' => $this->getSessionCount($app, maxAgeSeconds: 3600),
            'session_count_last_day' => $this->getSessionCount($app, maxAgeSeconds: 86400),
            'session_count_total' => $this->getSessionCount($app),
            'session_path' => $this->appsConfig[$app]['SESSION_PATH'],
            'logs' => $this->getAppLogs($app),
            'configs' => $this->watcherConfig[$app]['configs'],
            'display_errors' => $this->appsConfig[$app]['DISPLAY_ERRORS'],
            'log_level' => $this->appsConfig[$app]['LOG_HELPER_IMPORTANT'],
            'log_helper' => $this->appsConfig[$app]['LOG_HELPER'],
            'alarms_enabled' => $this->watcherConfig[$app]['alarms_enabled'] ?? '',
        ];
    }

    public function getAppLogStatus($app)
    {
        $app = strtolower($app);
        $this->loadAppConfig($app);

        return json_encode($this->getLogStats($app));

    }

    public function getAppLogs($app)
    {
        $logs = [];
        foreach ($this->watcherConfig[$app]['logs'] as $log) {
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

        return json_encode($logs);
    }

    public function loadAppConfig($app)
    {
        $result = [];
        $app = strtolower($app);
        $config = [];
        foreach ($this->watcherConfig[$app]['configs'] as $cfg) {
            // Get Config Contents
            $filePath = $this->rootPath . DIRECTORY_SEPARATOR . $cfg;
            if (file_exists($filePath)) {
                $config = array_merge($config, $this->tools->loadEnv($this->rootPath . DIRECTORY_SEPARATOR . $cfg));
            }
        }

        foreach ($this->transmitConfig['transmit_config'] as $transmit) {
            $result[$transmit] = $config[$transmit] ?? '';
        }
        $this->appsConfig[$app] = $result;

        return json_encode($result);
    }

    public function getSessionCount(string $app, int $maxAgeSeconds = 0): int
    {
        $now = time();
        $count = 0;
        $app = strtolower($app);

        $sessionPath = $this->watcherConfig[$app]['sessions']['path'] ?? '';
        if (empty($sessionPath)) {
            return 0;
        }

        $sessionsDirPath = $this->rootPath . DIRECTORY_SEPARATOR . $sessionPath;

        if (!is_dir($sessionsDirPath)) {
            return 0;
        }

        $allFiles = scandir($sessionsDirPath);
        if ($allFiles === false) {
            return 0;
        }

        $files = [];
        foreach ($allFiles as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $filenameRegex = $this->watcherConfig[$app]['sessions']['filename_regex'] ?? '';
            // If no regex provided, match all files; otherwise filter by regex
            if (empty($filenameRegex) || preg_match($filenameRegex, $filename)) {
                $files[] = $sessionsDirPath . DIRECTORY_SEPARATOR . $filename;
            }
        }

        if ($maxAgeSeconds > 0) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $mtime = filemtime($file);
                    $age = $now - $mtime;
                    if ($age >= 0 && $age <= $maxAgeSeconds) {
                        ++$count;
                    }
                }
            }

            return $count;
        }

        return count($files);
    }

    public function getLogEntries(string $app, string $filter = '', ?int $limit = null): string
    {
        $app = strtolower($app);
        $this->loadAppConfig($app);
        $limit ??= $this->transmitConfig['errorlog_readout_limit'] ?? 50;

        $logs = $this->watcherConfig[$app]['logs'] ?? [];
        if (empty($logs)) {
            return json_encode([]);
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

                // parser can be declader via app config or auto-detected
                $parser = $type !== ''
                    ? $this->getLogParserByType($type)
                    : $this->getLogParserByLogfile($logFile);
                $grepPattern = $parser->getGrepPattern($filter);

                $grepCmd = $grepPattern ? "grep '{$grepPattern}'" : 'cat';
                $cmd = 'tail -n 20000 ' . escapeshellarg($logFile) . " | {$grepCmd} | tail -n " . (int) $limit;
                $output = shell_exec($cmd);

                $lines = $output ? explode("\n", trim($output)) : [];
                $entries[$logPath] = $lines;
            }
        }

        return json_encode($entries);
    }

    public function getLogStats(string $app): array
    {
        $app = strtolower($app);
        $this->loadAppConfig($app);

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

                $parser = $type !== ''
                    ? $this->getLogParserByType($type)
                    : $this->getLogParserByLogfile($logFile);

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

        // composer-json: path, composer-lock: path
        $jsonPath ??= $config['composer-json'] ?? $config['composer_json'] ?? null;
        $lockPath ??= $config['composer-lock'] ?? $config['composer_lock'] ?? null;

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
     * Return a JSON string with all libraries from a composer.lock.
     *
     * @param string $lockFile Path to composer.lock
     *
     * @return string JSON for Tabulator (array of objects)
     *
     * @throws RuntimeException If file unreadable or JSON invalid
     */
    public function composerLibrariesToJson(string $lockFile): string
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

        return json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function readmeToJson($readmeFile): string
    {

        if (!is_readable($readmeFile)) {
            throw new RuntimeException("readme.md not found or unreadable: {$readmeFile}");
        }
        $data = file_get_contents($readmeFile);

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Get appropriate log parser for a log type.
     */
    private function getLogParserByType(string $type): object
    {
        $cacheKey = "type:{$type}";
        if (isset($this->parserCache[$cacheKey])) {
            return $this->parserCache[$cacheKey];
        }

        $parser = LogParserFactory::getParser($type);
        if ($parser === null) {
            error_log("Invalid log type '{$type}', using default parser");
            $parser = LogParserFactory::getParser('php_error_log');
        }

        $this->parserCache[$cacheKey] = $parser;

        return $parser;
    }

    /**
     * Get appropriate log parser by inspecting a log file.
     */
    private function getLogParserByLogfile(string $logFile): object
    {
        $cacheKey = "file:{$logFile}";
        if (isset($this->parserCache[$cacheKey])) {
            return $this->parserCache[$cacheKey];
        }

        $parser = LogParserFactory::detectFormat($logFile);

        $this->parserCache[$cacheKey] = $parser;

        return $parser;
    }
}
