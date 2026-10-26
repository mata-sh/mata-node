<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

/**
 * Parser for JSON structured logs.
 *
 * Handles JSON format: {"timestamp":"...","level":"...","message":"..."}
 * Expects one JSON object per line (standard practice).
 */
class JsonParser extends AbstractLogParser
{
    /** Words in every line whose level is an error or a warning; other lines are not decoded. */
    private const SEVERE_LEVEL_HINT = '/error|critical|alert|emergency|warn/i';

    public function getName(): string
    {
        return 'json';
    }

    protected function lineType(string $line): string
    {
        if (preg_match(self::SEVERE_LEVEL_HINT, $line) !== 1) {
            return 'Other';
        }

        $decoded = json_decode(trim($line), true);

        return is_array($decoded) ? self::type(self::level($decoded)) : 'Other';
    }

    /** A JSON object with a level, timestamp or message. */
    protected function isFormatLine(string $line): bool
    {
        $decoded = json_decode(trim($line), true);

        return is_array($decoded)
            && (isset($decoded['level']) || isset($decoded['timestamp']) || isset($decoded['message']));
    }

    /**
     * Canonicalize a JSON record so the digest does not depend on key order.
     */
    protected function canonicalizeEntryLine(string $line): string
    {
        return $this->canonicalJsonLine($line);
    }

    protected function parseEntry(string $entry): ?array
    {
        $decoded = json_decode(trim($entry), true);
        if (!is_array($decoded)) {
            return null;
        }

        // Normalize to standard format
        $timestamp = $decoded['timestamp'] ?? $decoded['time'] ?? $decoded['@timestamp'] ?? $decoded['datetime'] ?? '';
        $message = $decoded['message'] ?? $decoded['msg'] ?? '';

        $parsed = [
            'timestamp' => $this->normalizeTimestamp((string) $timestamp),
            'type' => self::type(self::level($decoded)),
            'message' => (string) $message,
        ];

        // Include additional fields if present
        if (isset($decoded['context'])) {
            $parsed['context'] = $decoded['context'];
        }
        if (isset($decoded['extra'])) {
            $parsed['extra'] = $decoded['extra'];
        }

        return $parsed;
    }

    /**
     * The record's level, `info` when it has none. Monolog's JsonFormatter writes `level` as a
     * number and the name as `level_name`; numbers differ between loggers, so only names count.
     *
     * @param array<mixed> $record
     */
    private static function level(array $record): string
    {
        $level = $record['level_name'] ?? $record['level'] ?? $record['severity'] ?? 'info';

        return is_scalar($level) ? (string) $level : '';
    }

    /** A level as entry type, in the types of the PHP error_log format. */
    private static function type(string $level): string
    {
        return match (strtolower($level)) {
            'error', 'critical', 'alert', 'emergency' => 'Error',
            'warning', 'warn' => 'Warning',
            default => 'Other'
        };
    }
}
