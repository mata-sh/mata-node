<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

/**
 * Parser for Monolog line format.
 *
 * Handles Monolog format: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: message {context}, with the
 * timestamp also in Monolog's default form [YYYY-MM-DDTHH:MM:SS.uuuuuu+00:00].
 * Supports multi-line entries (stack traces, context blocks).
 */
class MonologParser extends AbstractLogParser
{
    /** [2026-01-18 10:30:45], or with `T`, fractional seconds and an offset or `Z` */
    private const TIMESTAMP = '\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\]';

    private const ENTRY_START_PATTERN = '/^' . self::TIMESTAMP . '/';

    /** [timestamp] channel.LEVEL: */
    private const FORMAT_LINE_PATTERN = '/^' . self::TIMESTAMP . ' \w+\.\w+:/';

    /** An entry: [timestamp] channel.LEVEL: message, the message running on over continuation lines. */
    private const ENTRY_PATTERN = '/^\[(.*?)\] (\w+)\.(\w+): (.+)$/s';

    public function getName(): string
    {
        return 'monolog';
    }

    protected function lineType(string $line): string
    {
        return preg_match(self::ENTRY_PATTERN, $line, $matches) === 1 ? self::type($matches[3]) : 'Other';
    }

    protected function isFormatLine(string $line): bool
    {
        return preg_match(self::FORMAT_LINE_PATTERN, $line) === 1;
    }

    protected function entryStartPattern(): string
    {
        return self::ENTRY_START_PATTERN;
    }

    protected function parseEntry(string $entry): ?array
    {
        if (preg_match(self::ENTRY_PATTERN, $entry, $matches) !== 1) {
            return null;
        }

        [, $timestamp, $channel, $level, $message] = $matches;

        return [
            'timestamp' => $this->normalizeTimestamp($timestamp),
            'type' => self::type($level),
            'message' => trim($message),
            'channel' => $channel,
            'level' => $level,
        ];
    }

    /** A Monolog level as entry type, in the types of the PHP error_log format. */
    private static function type(string $level): string
    {
        return match (strtoupper($level)) {
            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => 'Error',
            'WARNING' => 'Warning',
            default => 'Other'
        };
    }
}
