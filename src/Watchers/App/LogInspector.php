<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\ShellCommands;
use Mata\Watchers\LogParsers\LogParserFactory;
use Mata\Watchers\LogParsers\LogParserInterface;
use Psr\Log\LoggerInterface;

final class LogInspector
{
    private const DEFAULT_LOG_SCAN_LINE_LIMIT = 20000;
    private const LOG_FILTER_ALL = '';
    private const LOG_FILTER_ERRORS = 'errors';
    private const LOG_FILTER_WARNINGS = 'warnings';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $rootPath,
        private readonly array $watcherConfig,
        private readonly array $transmitConfig,
        private readonly CommandRunnerInterface $commandRunner,
    ) {}

    public function files(string $app): array
    {
        $logs = [];
        foreach ($this->getConfiguredLogPaths($app) as $log) {
            $filePath = $this->resolvePath($log);
            if (is_dir($filePath)) {
                foreach (glob($filePath . DIRECTORY_SEPARATOR . '*.log') ?: [] as $logFile) {
                    $logs[$log . DIRECTORY_SEPARATOR . basename($logFile)] = filesize($logFile) / 1024;
                }

                continue;
            }
            if (file_exists($filePath)) {
                $logs[$log] = filesize($filePath) / 1024;
            }
        }

        return $logs;
    }

    public function entries(string $app, string $filter = '', ?int $limit = null): array
    {
        $limit = max(1, (int) ($limit ?? $this->transmitConfig['errorlog_readout_limit'] ?? 50));
        $scanLineLimit = max(1, (int) ($this->transmitConfig['errorlog_scan_line_limit'] ?? self::DEFAULT_LOG_SCAN_LINE_LIMIT));

        if (!$this->isValidLogFilter($filter)) {
            $this->logger->warning('Invalid log filter requested', [
                'app' => $app,
                'filter' => $filter,
            ]);

            return [];
        }

        $entries = [];
        foreach ($this->resolveLogPaths($app) as $logConfig) {
            $logPath = $logConfig['path'];
            $type = $logConfig['type'];
            $logFile = $this->resolvePath($logPath);

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

        return $entries;
    }

    public function status(string $app): array
    {
        $status = [];
        foreach ($this->resolveLogPaths($app) as $logConfig) {
            $logPath = $logConfig['path'];
            $logFile = $this->resolvePath($logPath);
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

        return $status;
    }

    public function stats(string $app): array
    {
        $totals = [
            'fatal' => 0,
            'warnings' => 0,
            'total' => 0,
        ];

        foreach ($this->resolveLogPaths($app) as $logConfig) {
            $logPath = $logConfig['path'];
            $type = $logConfig['type'];
            $logFile = $this->resolvePath($logPath);
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

        return $totals;
    }

    /**
     * @return array<int, array{path: string, type: mixed}>
     */
    private function resolveLogPaths(string $app): array
    {
        $logs = $this->watcherConfig[$app]['logs'] ?? [];
        $resolved = [];

        if (empty($logs)) {
            return $resolved;
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

                $resolved[] = [
                    'path' => $logPath,
                    'type' => $type,
                ];
            }
        }

        return $resolved;
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

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->rootPath . DIRECTORY_SEPARATOR . $path;
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
        $output = $this->commandRunner->run(ShellCommands::tail($logFile, $maxLines));

        return $output !== null && trim($output) !== '' ? explode("\n", trim($output)) : [];
    }

    private function countFileLines(string $logFile): int
    {
        $output = $this->commandRunner->run(ShellCommands::lineCount($logFile));

        return $output === null ? 0 : (int) trim($output);
    }

    private function getLogParser(string $type, string $logFile): LogParserInterface
    {
        if ($type === '') {
            return LogParserFactory::detectFormat($logFile, $this->logger, $this->commandRunner);
        }

        $parser = LogParserFactory::getParser($type, $this->logger, $this->commandRunner);
        if ($parser !== null) {
            return $parser;
        }

        $this->logger->warning('Invalid log parser type configured, using auto-detected parser', [
            'type' => $type,
            'log' => $logFile,
        ]);

        return LogParserFactory::detectFormat($logFile, $this->logger, $this->commandRunner);
    }
}
