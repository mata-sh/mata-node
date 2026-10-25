<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\ShellCommands;
use Mata\Utils\Tools;
use Mata\Watchers\LogParsers\LogParserFactory;
use Mata\Watchers\LogParsers\LogParserInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class LogInspector
{
    private const DEFAULT_LOG_SCAN_LINE_LIMIT = 20000;
    private const LOG_FILTER_ALL = '';
    private const LOG_FILTER_ERRORS = 'errors';
    private const LOG_FILTER_WARNINGS = 'warnings';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $rootPath,
        private readonly Tools $tools,
        private readonly array $watcherConfig,
        private readonly array $transmitConfig,
        private readonly CommandRunnerInterface $commandRunner,
    ) {}

    public function files(string $app): array
    {
        $logs = [];
        foreach ($this->resolveLogPaths($app, expandDirectories: true) as $logConfig) {
            $logPath = $logConfig['path'];
            $filePath = $this->tools->normalizePath($logPath, $this->rootPath);
            if (file_exists($filePath)) {
                $logs[$logPath] = filesize($filePath) / 1024;
            }
        }

        return $logs;
    }

    public function entries(string $app, string $filter = '', ?int $limit = null): array
    {
        $limit = $this->readoutLimit($limit);
        $scanLineLimit = $this->scanLineLimit();

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
            $logFile = $this->tools->normalizePath($logPath, $this->rootPath);

            if (!is_readable($logFile)) {
                $this->logger->warning('Log file not found or unreadable', [
                    'app' => $app,
                    'log' => $logFile,
                ]);
                $entries[$logPath] = [];

                continue;
            }

            try {
                $parser = $this->getLogParser($type, $logFile);
                $lines = $this->tailLogLines($logFile, $scanLineLimit);
                $parsedEntries = $parser->parseLines($lines);
                $parsedEntries = $this->filterLogEntries($parsedEntries, $filter);
                $entries[$logPath] = array_slice($parsedEntries, -$limit);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to parse log file', [
                    'app' => $app,
                    'log' => $logFile,
                    'exception' => $e,
                ]);
                $entries[$logPath] = [];
            }
        }

        return $entries;
    }

    public function readoutLimit(?int $limit = null): int
    {
        return max(1, (int) ($limit ?? $this->transmitConfig['errorlog_readout_limit'] ?? 50));
    }

    public function status(string $app): array
    {
        $status = [];
        foreach ($this->resolveLogPaths($app, expandDirectories: true) as $logConfig) {
            $logPath = $logConfig['path'];
            $logFile = $this->tools->normalizePath($logPath, $this->rootPath);
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

    /**
     * What the parsing endpoints make of each configured log file, for `bin/mata-node log test`.
     *
     * Files found in a configured directory are listed but not parsed, as for `/logs`.
     * `type_known` is false when a configured type names no parser and the format is detected.
     *
     * @return list<array{
     *     path: string,
     *     file: string,
     *     readable: bool,
     *     parsed: bool,
     *     type: string,
     *     type_known: bool,
     *     parser: ?string,
     *     lines: int,
     *     entries: int,
     *     fatal: int,
     *     warnings: int,
     *     total: int,
     *     samples: list<array<string, mixed>>,
     *     error: ?string
     * }>
     */
    public function diagnose(string $app, int $sampleLimit): array
    {
        $scanLineLimit = $this->scanLineLimit();
        $knownTypes = LogParserFactory::getAvailableParsers();

        $files = [];
        foreach ($this->resolveLogPaths($app, expandDirectories: true) as $logConfig) {
            $type = is_string($logConfig['type']) ? $logConfig['type'] : '';
            $logFile = $this->tools->normalizePath($logConfig['path'], $this->rootPath);
            $file = [
                'path' => $logConfig['path'],
                'file' => $logFile,
                'readable' => is_file($logFile) && is_readable($logFile),
                'parsed' => !$logConfig['from_directory'],
                'type' => $type,
                'type_known' => $type === '' || in_array($type, $knownTypes, true),
                'parser' => null,
                'lines' => 0,
                'entries' => 0,
                'fatal' => 0,
                'warnings' => 0,
                'total' => 0,
                'samples' => [],
                'error' => null,
            ];

            if ($file['readable'] && $file['parsed']) {
                try {
                    $parser = $this->getLogParser($type, $logFile);
                    $lines = $this->tailLogLines($logFile, $scanLineLimit);
                    $entries = $parser->parseLines($lines);
                    $stats = $parser->analyzeLog($logFile);
                    $file = array_replace($file, [
                        'parser' => $parser->getName(),
                        'lines' => count($lines),
                        'entries' => count($entries),
                        'fatal' => $stats->fatal,
                        'warnings' => $stats->warnings,
                        'total' => $stats->total,
                        'samples' => $sampleLimit > 0 ? array_values(array_slice($entries, -$sampleLimit)) : [],
                    ]);
                } catch (Throwable $e) {
                    $file['error'] = $e->getMessage();
                }
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * Aggregated log counts plus the app's fatal cursor.
     *
     * The cursor is null exactly when the fatal count is zero.
     *
     * @return array{fatal: int, warnings: int, total: int, fatal_cursor: null|string}
     */
    public function stats(string $app): array
    {
        $fatal = 0;
        $warnings = 0;
        $total = 0;

        /** @var array<int, array{path: string, parser: string, digest: string}> $observations */
        $observations = [];

        foreach ($this->resolveLogPaths($app) as $logConfig) {
            $logPath = $logConfig['path'];
            $type = $logConfig['type'];
            $logFile = $this->tools->normalizePath($logPath, $this->rootPath);

            if (!is_readable($logFile)) {
                $this->logger->warning('Log file not found or unreadable', [
                    'app' => $app,
                    'log' => $logFile,
                ]);

                continue;
            }

            $parser = $this->getLogParser($type, $logFile);

            $stats = $parser->analyzeLog($logFile);
            $fatal += $stats->fatal;
            $warnings += $stats->warnings;
            $total += $stats->total;

            if ($stats->fatalDigest !== null) {
                $observations[] = [
                    'path' => $logPath,
                    'parser' => $stats->parserName,
                    'digest' => $stats->fatalDigest,
                ];
            }
        }

        return [
            'fatal' => $fatal,
            'warnings' => $warnings,
            'total' => $total,
            'fatal_cursor' => FatalCursor::fromObservations($observations),
        ];
    }

    /**
     * Resolve configured log paths for an app.
     *
     * Directory configs are metadata-only: with $expandDirectories they are
     * expanded into the *.log files they contain (files/status endpoints);
     * otherwise they are skipped (parsing endpoints).
     *
     * @return array<int, array{path: string, type: mixed, from_directory: bool}>
     */
    private function resolveLogPaths(string $app, bool $expandDirectories = false): array
    {
        $logs = $this->watcherConfig[$app]['logs'] ?? [];
        $resolved = [];

        if (!is_array($logs) || empty($logs)) {
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

            if (!is_array($paths) || empty($paths)) {
                continue;
            }

            foreach ($paths as $logPath) {
                if (!is_string($logPath) || $logPath === '') {
                    continue;
                }

                $candidatePaths = [$logPath];
                $fromDirectory = is_dir($this->tools->normalizePath($logPath, $this->rootPath));
                if ($fromDirectory) {
                    if (!$expandDirectories) {
                        continue;
                    }

                    $candidatePaths = $this->expandDirectoryPath($logPath);
                }

                foreach ($candidatePaths as $resolvedPath) {
                    $resolved[] = [
                        'path' => $resolvedPath,
                        'type' => $type,
                        'from_directory' => $fromDirectory,
                    ];
                }
            }
        }

        return $resolved;
    }

    /**
     * Expand a configured directory path into the *.log files it contains.
     *
     * @return array<int, string>
     */
    private function expandDirectoryPath(string $logPath): array
    {
        $filePath = $this->tools->normalizePath($logPath, $this->rootPath);

        $expanded = [];
        foreach (glob($filePath . DIRECTORY_SEPARATOR . '*.log') ?: [] as $logFile) {
            $expanded[] = $logPath . DIRECTORY_SEPARATOR . basename($logFile);
        }

        return $expanded;
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

    /** Most recent lines of a log file the parsing endpoints read. */
    private function scanLineLimit(): int
    {
        return max(1, (int) ($this->transmitConfig['errorlog_scan_line_limit'] ?? self::DEFAULT_LOG_SCAN_LINE_LIMIT));
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
