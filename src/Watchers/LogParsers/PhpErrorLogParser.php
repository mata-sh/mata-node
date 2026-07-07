<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use RuntimeException;

/**
 * Parser for PHP error_log format.
 *
 * Handles standard PHP error log format: [DD-Mon-YYYY HH:MM:SS timezone] PHP ErrorType: message
 * Supports multi-line entries (stack traces).
 */
class PhpErrorLogParser extends AbstractLogParser
{
    public function getName(): string
    {
        return 'php_error_log';
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

        // Pattern: [DD-Mon-YYYY HH:MM:SS timezone] PHP
        $pattern = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [^\]]+\] PHP/';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                ++$matchCount;
            }
        }

        // Return true if 50%+ of lines match PHP error_log format
        return $totalLines > 0 && ($matchCount / $totalLines) >= 0.5;
    }

    public function getGrepPattern(string $filter): string
    {
        return match ($filter) {
            'errors' => 'PHP Fatal error\|PHP Parse error\|PHP Error',
            'warnings' => 'PHP Warning',
            default => '',
        };
    }

    /**
     * Parse PHP error log lines.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseLines(array $lines): array
    {
        $entryStartPattern = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [^\]]+\]/';
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
     * Parse a single PHP error log entry.
     *
     * @return null|array<string,mixed>
     */
    public function parse(string $entry): ?array
    {
        if (!preg_match('/^\[(.*?)\] PHP ([\w\s]+): (.+)$/s', $entry, $matches)) {
            return null;
        }

        $timestamp = $matches[1];
        $type = trim($matches[2]);
        $message = trim($matches[3]);

        $normalizedType = match (true) {
            stripos($type, 'warning') !== false => 'Warning',
            stripos($type, 'error') !== false => 'Error',
            stripos($type, 'deprecated') !== false => 'Deprecated',
            default => 'Other'
        };

        return [
            'timestamp' => $this->normalizeTimestamp($timestamp),
            'type' => $normalizedType,
            'message' => $message,
        ];
    }

    /**
     * Parse PHP error log to JSON format.
     *
     * Extracted from AppWatcher::errorlogToJson() for backward compatibility.
     */
    public function parseToJson(string $logFile, int $maxLines): string
    {
        if (!file_exists($logFile) || !is_readable($logFile)) {
            throw new RuntimeException("errorlog not found or unreadable: {$logFile}");
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

    /**
     * Analyze error log and return counts.
     */
    public function analyzeLog(string $logFile): array
    {
        if (!file_exists($logFile) || !is_readable($logFile)) {
            return [
                'fatal' => 0,
                'warnings' => 0,
                'total' => 0,
            ];
        }

        $warningCount = (int) $this->shell("grep -c 'PHP Warning' " . escapeshellarg($logFile));
        $errorCount = (int) $this->shell("grep -c 'PHP Fatal error\\|PHP Parse error\\|PHP Error' " . escapeshellarg($logFile));
        $totalLines = (int) $this->shell('wc -l < ' . escapeshellarg($logFile));

        return [
            'fatal' => $errorCount,
            'warnings' => $warningCount,
            'total' => $totalLines,
        ];
    }
}
