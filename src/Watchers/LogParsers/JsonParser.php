<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use RuntimeException;

/**
 * Parser for JSON structured logs.
 *
 * Handles JSON format: {"timestamp":"...","level":"...","message":"..."}
 * Expects one JSON object per line (standard practice).
 */
class JsonParser extends AbstractLogParser
{
    public function getName(): string
    {
        return 'json';
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

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Check for required fields
                if (isset($decoded['level']) || isset($decoded['timestamp']) || isset($decoded['message'])) {
                    ++$matchCount;
                }
            }
        }

        // Return true if 50%+ of lines are valid JSON with required fields
        return $totalLines > 0 && ($matchCount / $totalLines) >= 0.5;
    }

    private function getGrepPattern(string $filter): string
    {
        return match ($filter) {
            'errors' => '"level":"error"\|"level":"critical"\|"level":"emergency"\|"level":"alert"',
            'warnings' => '"level":"warning"',
            default => '',
        };
    }

    /**
     * Parse JSON log lines.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseLines(array $lines): array
    {
        $parsedEntries = [];

        foreach ($lines as $line) {
            $parsed = $this->parse($line);
            if ($parsed !== null) {
                $parsedEntries[] = $parsed;
            }
        }

        return $parsedEntries;
    }

    /**
     * Parse a single JSON log line.
     *
     * @return null|array<string,mixed>
     */
    public function parse(string $line): ?array
    {
        $line = trim($line);
        if (empty($line)) {
            return null;
        }

        $decoded = json_decode($line, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        // Normalize to standard format
        $timestamp = $decoded['timestamp'] ?? $decoded['time'] ?? $decoded['@timestamp'] ?? '';
        $level = $decoded['level'] ?? $decoded['severity'] ?? 'info';
        $message = $decoded['message'] ?? $decoded['msg'] ?? '';

        // Normalize log level to match PHP error_log format
        $normalizedType = match (strtolower((string) $level)) {
            'error', 'critical', 'alert', 'emergency' => 'Error',
            'warning', 'warn' => 'Warning',
            'notice', 'info', 'debug' => 'Other',
            default => 'Other'
        };

        $entry = [
            'timestamp' => $this->normalizeTimestamp((string) $timestamp),
            'type' => $normalizedType,
            'message' => (string) $message,
        ];

        // Include additional fields if present
        if (isset($decoded['context'])) {
            $entry['context'] = $decoded['context'];
        }
        if (isset($decoded['extra'])) {
            $entry['extra'] = $decoded['extra'];
        }

        return $entry;
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
        $errorPattern = $this->getGrepPattern('errors');
        $errorCount = (int) $this->shell("grep -c '{$errorPattern}' " . escapeshellarg($logFile) . ' 2>/dev/null || echo 0');

        // Count warning entries
        $warningPattern = $this->getGrepPattern('warnings');
        $warningCount = (int) $this->shell("grep -c '{$warningPattern}' " . escapeshellarg($logFile) . ' 2>/dev/null || echo 0');

        // Total lines
        $totalLines = (int) $this->shell('wc -l < ' . escapeshellarg($logFile));

        return [
            'fatal' => $errorCount,
            'warnings' => $warningCount,
            'total' => $totalLines,
        ];
    }

    protected function normalizeTimestamp(string $timestamp): string
    {
        if ($timestamp === '') {
            return '';
        }

        return parent::normalizeTimestamp($timestamp);
    }
}
