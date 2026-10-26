<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

/**
 * Parser for PHP error_log format.
 *
 * Handles standard PHP error log format: [DD-Mon-YYYY HH:MM:SS timezone] PHP ErrorType: message
 * Supports multi-line entries (stack traces).
 */
class PhpErrorLogParser extends AbstractLogParser
{
    private const ENTRY_START_PATTERN = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [^\]]+\]/';

    /** [DD-Mon-YYYY HH:MM:SS timezone] PHP */
    private const FORMAT_LINE_PATTERN = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [^\]]+\] PHP/';

    /** An entry: [timestamp] PHP Error type: message, the message running on over continuation lines. */
    private const ENTRY_PATTERN = '/^\[(.*?)\] PHP ([\w\s]+): (.+)$/s';

    public function getName(): string
    {
        return 'php_error_log';
    }

    /** Lines such as `PHP Stack trace:` and `PHP   1. {main}()` start no entry of their own type. */
    protected function lineType(string $line): string
    {
        return preg_match(self::ENTRY_PATTERN, $line, $matches) === 1 ? self::type($matches[2]) : 'Other';
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

        return [
            'timestamp' => $this->normalizeTimestamp($matches[1]),
            'type' => self::type($matches[2]),
            'message' => trim($matches[3]),
        ];
    }

    /** A PHP error type such as `Fatal error` or `Notice` as entry type. */
    private static function type(string $phpType): string
    {
        return match (true) {
            stripos($phpType, 'warning') !== false,
            stripos($phpType, 'notice') !== false => 'Warning',
            stripos($phpType, 'error') !== false => 'Error',
            stripos($phpType, 'deprecated') !== false => 'Deprecated',
            default => 'Other'
        };
    }
}
