<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Mata\Services\Command\ShellCommands;
use RuntimeException;

/**
 * Parser for Monolog line format.
 *
 * Handles Monolog format: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: message {context}
 * Supports multi-line entries (stack traces, context blocks).
 */
class MonologParser extends AbstractLogParser
{
    private const ENTRY_START_PATTERN = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/';

    public function getName(): string
    {
        return 'monolog';
    }

    public function detect(string $logFile): bool
    {
        if (!file_exists($logFile) || !is_readable($logFile)) {
            return false;
        }

        $output = $this->shell(ShellCommands::head($logFile, 10));
        if ($output === null || $output === '') {
            return false;
        }

        $lines = explode("\n", trim($output));
        $matchCount = 0;
        $totalLines = count($lines);

        // Pattern: [YYYY-MM-DD HH:MM:SS] channel.LEVEL:
        $pattern = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \w+\.\w+:/';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                ++$matchCount;
            }
        }

        // Return true if 50%+ of lines match Monolog format
        return $totalLines > 0 && ($matchCount / $totalLines) >= 0.5;
    }

    protected function fatalLinePattern(): string
    {
        return '/.ERROR:|.CRITICAL:|.EMERGENCY:|.ALERT:/';
    }

    protected function warningLinePattern(): string
    {
        return '/.WARNING:/';
    }

    protected function entryStartPattern(): string
    {
        return self::ENTRY_START_PATTERN;
    }

    /**
     * Parse Monolog lines.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseLines(array $lines): array
    {
        // Monolog entry starts with: [YYYY-MM-DD HH:MM:SS]
        $logEntries = $this->groupMultilineEntries($lines, self::ENTRY_START_PATTERN);

        $parsedEntries = [];
        foreach ($logEntries as $entry) {
            $parsed = $this->parse($entry);
            if ($parsed !== null) {
                $parsedEntries[] = $parsed;
            }
        }

        return $parsedEntries;
    }

    /**
     * Parse a single Monolog entry.
     *
     * @return null|array<string,mixed>
     */
    public function parse(string $entry): ?array
    {
        // Parse: [2026-01-18 10:30:45] channel.LEVEL: message {context}
        if (!preg_match('/^\[(.*?)\] (\w+)\.(\w+): (.+)$/s', $entry, $matches)) {
            return null;
        }

        $timestamp = $matches[1];
        $channel = $matches[2];
        $level = $matches[3];
        $message = trim($matches[4]);

        // Normalize log level to match PHP error_log format
        $normalizedType = match (strtoupper($level)) {
            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'Error',
            'WARNING' => 'Warning',
            'NOTICE', 'INFO' => 'Other',
            'DEBUG' => 'Other',
            default => 'Other'
        };

        return [
            'timestamp' => $this->normalizeTimestamp($timestamp),
            'type' => $normalizedType,
            'message' => $message,
            'channel' => $channel,
            'level' => $level,
        ];
    }

    public function parseToJson(string $logFile, int $maxLines): string
    {
        if (!file_exists($logFile) || !is_readable($logFile)) {
            throw new RuntimeException("log file not found or unreadable: {$logFile}");
        }

        $output = $this->shell(ShellCommands::tail($logFile, $maxLines));
        if ($output === null) {
            throw new RuntimeException('failed to read log using tail');
        }

        $lines = trim($output) !== '' ? explode("\n", trim($output)) : [];

        return json_encode($this->parseLines($lines), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
