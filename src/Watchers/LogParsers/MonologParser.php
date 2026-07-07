<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use RuntimeException;

/**
 * Parser for Monolog line format.
 *
 * Handles Monolog format: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: message {context}
 * Supports multi-line entries (stack traces, context blocks).
 */
class MonologParser extends AbstractLogParser
{
    public function getName(): string
    {
        return 'monolog';
    }

    public function detect(string $logFile): bool
    {
        if (!file_exists($logFile) || !is_readable($logFile)) {
            return false;
        }

        $output = $this->shell('head -n 10 ' . escapeshellarg($logFile));
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

    public function getGrepPattern(string $filter): string
    {
        return match ($filter) {
            'errors' => '.ERROR:|.CRITICAL:|.EMERGENCY:|.ALERT:',
            'warnings' => '.WARNING:',
            default => '',
        };
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
        $entryStartPattern = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/';
        $logEntries = $this->groupMultilineEntries($lines, $entryStartPattern);

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

        $escapedLogFile = escapeshellarg($logFile);
        $escapedLines = intval($maxLines);

        $output = $this->shell("tail -n {$escapedLines} {$escapedLogFile}");
        if ($output === null) {
            throw new RuntimeException('failed to read log using tail');
        }

        $lines = trim($output) !== '' ? explode("\n", trim($output)) : [];

        return json_encode($this->parseLines($lines), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function analyzeLog(string $logFile): array
    {
        if (!file_exists($logFile) || !is_readable($logFile)) {
            return [
                'fatal' => 0,
                'warnings' => 0,
                'total' => 0,
            ];
        }

        // Count error-level entries
        $errorPattern = '.ERROR:\|.CRITICAL:\|.EMERGENCY:\|.ALERT:';
        $errorCount = (int) $this->shell("grep -c '{$errorPattern}' " . escapeshellarg($logFile) . ' 2>/dev/null || echo 0');

        // Count warning entries
        $warningCount = (int) $this->shell("grep -c '.WARNING:' " . escapeshellarg($logFile) . ' 2>/dev/null || echo 0');

        // Total lines
        $totalLines = (int) $this->shell('wc -l < ' . escapeshellarg($logFile));

        return [
            'fatal' => $errorCount,
            'warnings' => $warningCount,
            'total' => $totalLines,
        ];
    }
}
