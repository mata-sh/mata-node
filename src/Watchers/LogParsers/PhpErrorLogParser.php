<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Mata\Services\Command\ShellCommands;
use RuntimeException;

/**
 * Parser for PHP error_log format.
 *
 * Handles standard PHP error log format: [DD-Mon-YYYY HH:MM:SS timezone] PHP ErrorType: message
 * Supports multi-line entries (stack traces).
 */
class PhpErrorLogParser extends AbstractLogParser
{
    private const ENTRY_START_PATTERN = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [^\]]+\]/';

    public function getName(): string
    {
        return 'php_error_log';
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

    protected function fatalLinePattern(): string
    {
        return '/^\[[^\]]*\] PHP [^:]*(error|Error)[^:]*:/';
    }

    protected function warningLinePattern(): string
    {
        return '/^\[[^\]]*\] PHP [^:]*(Warning|warning|Notice|notice)[^:]*:/';
    }

    protected function entryStartPattern(): string
    {
        return self::ENTRY_START_PATTERN;
    }

    protected function severityPatternsMatchLineStartOnly(): bool
    {
        return true;
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
            stripos($type, 'warning') !== false,
            stripos($type, 'notice') !== false => 'Warning',
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

        $output = $this->shell(ShellCommands::tail($logFile, $maxLines));
        if ($output === null) {
            throw new RuntimeException('failed to read log using tail');
        }

        $lines = trim($output) !== '' ? explode("\n", trim($output)) : [];

        return json_encode($this->parseLines($lines), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
