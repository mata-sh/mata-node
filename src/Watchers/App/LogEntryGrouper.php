<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

/**
 * Collapses repeated log entries into one entry per message.
 *
 * Entries group when their type matches and so does their message once timestamps, IDs and
 * numbers are masked. A group is its newest entry plus `count`, `first_seen` and `last_seen`;
 * `timestamp` stays the newest one, so readers that know nothing of groups still see when a
 * message last happened. Groups keep the order of their newest entry, oldest first.
 */
final class LogEntryGrouper
{
    /**
     * Variable parts of a message, masked for the group key. A source location (`Db.php:75`,
     * `line 75`) is matched first and kept: the same message from another line is another problem.
     * Every alternative is anchored or bounded, so masking stays linear on long messages.
     */
    private const VARIABLE_PARTS = '~(?<keep>\.[a-z]\w{0,9}:\d+|\bline \d+)'
        . '|\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?'
        . '|\b[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\b'
        . '|\b0x[0-9a-f]+\b'
        . '|\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b'
        . '|\b\d+(?:\.\d+)*~i';

    /**
     * @param array<int,array<string,mixed>> $entries parsed entries, oldest first
     *
     * @return list<array<string,mixed>>
     */
    public static function group(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = self::key($entry);
            $timestamp = is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : '';
            $previous = $groups[$key] ?? null;

            // Re-inserting moves the group to the end, behind every group seen less recently.
            unset($groups[$key]);
            $groups[$key] = [
                ...$entry,
                'count' => ($previous['count'] ?? 0) + 1,
                'first_seen' => $previous['first_seen'] ?? $timestamp,
                'last_seen' => $timestamp,
            ];
        }

        return array_values($groups);
    }

    /** @param array<string,mixed> $entry */
    private static function key(array $entry): string
    {
        $type = is_scalar($entry['type'] ?? null) ? (string) $entry['type'] : '';
        $message = is_scalar($entry['message'] ?? null) ? (string) $entry['message'] : '';

        return hash('xxh128', $type . "\0" . self::mask($message));
    }

    /** The message with its variable parts replaced by `#`; unmasked if PCRE gives up on it. */
    private static function mask(string $message): string
    {
        $masked = preg_replace_callback(
            self::VARIABLE_PARTS,
            static function (array $match): string {
                $isSourceLocation = ($match['keep'] ?? '') !== '';

                return $isSourceLocation ? $match[0] : '#';
            },
            $message,
        );

        return $masked ?? $message;
    }
}
