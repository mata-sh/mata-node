<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Mata\Config\Dto\LogFormatConfig;

/**
 * Parses a format defined in YAML (see LogFormatLoader). With a pattern, a line it matches starts
 * an entry and the lines up to the next match belong to its message, like a stack trace. A JSON
 * format reads one record per line. The level decides the type and the fatal and warning counts.
 *
 * Never auto-detected: apps.json names the format as `type`.
 */
final class DefinedLogParser extends AbstractLogParser
{
    /** Bytes of a line a pattern sees; it does not match the rest of a longer line. */
    public const MAX_MATCHED_BYTES = 8192;

    /** Backtracking steps one match may take, so no log line can make a pattern slow. */
    public const MATCH_LIMIT = 100000;

    private const CORE_FIELDS = ['timestamp', 'type', 'message', 'level'];

    /** The last line matched and its named groups (null: no match), as scan() asks twice per line. */
    private ?string $lastLine = null;

    /** @var null|array<string, string> */
    private ?array $lastGroups = null;

    public function __construct(private readonly LogFormatConfig $format) {}

    /** A pattern as written in a format file, without delimiters or flags, as PCRE with the match limit. */
    public static function compile(string $pattern): string
    {
        return "\x01(*LIMIT_MATCH=" . self::MATCH_LIMIT . ')' . $pattern . "\x01";
    }

    public function getName(): string
    {
        return $this->format->name;
    }

    /** Never auto-detected, so no line is a format line. */
    protected function isFormatLine(string $line): bool
    {
        return false;
    }

    protected function entryStartPattern(): ?string
    {
        return $this->format->pattern;
    }

    protected function startsEntry(string $line): bool
    {
        return $this->format->pattern === null || $this->groups($line) !== null;
    }

    protected function lineType(string $line): string
    {
        return $this->format->type($this->level($line));
    }

    protected function canonicalizeEntryLine(string $line): string
    {
        return $this->format->pattern === null ? $this->canonicalJsonLine($line) : $line;
    }

    /**
     * A JSON record, or a matched first line whose message continues on the lines after it, like
     * a stack trace. Named groups other than the core fields are kept as extra fields.
     */
    protected function parseEntry(string $entry): ?array
    {
        if ($this->format->pattern === null) {
            $record = json_decode($entry, true);
            if (!is_array($record)) {
                return null;
            }

            return $this->entry(
                $this->recordField($record, 'timestamp'),
                $this->recordField($record, 'level'),
                $this->recordField($record, 'message'),
            );
        }

        $continuation = explode("\n", $entry);
        $groups = $this->groups(array_shift($continuation)) ?? [];
        $message = implode("\n", [$groups['message'] ?? '', ...$continuation]);
        $extra = array_diff_key($groups, array_flip(self::CORE_FIELDS));

        return $this->entry($groups['timestamp'] ?? '', $groups['level'] ?? '', $message) + $extra;
    }

    /** @return array<string,mixed> */
    private function entry(string $timestamp, string $level, string $message): array
    {
        return [
            'timestamp' => $this->normalizeTimestamp($timestamp),
            'type' => $this->format->type($level),
            'message' => trim($message),
            'level' => $level,
        ];
    }

    /** The level of a line, or '' for a line that starts no entry or is no JSON record. */
    private function level(string $line): string
    {
        if ($this->format->pattern !== null) {
            return $this->groups($line)['level'] ?? '';
        }

        $record = json_decode($line, true);

        return is_array($record) ? $this->recordField($record, 'level') : '';
    }

    /**
     * The record field the format maps $key to, as text; '' when the format maps none (an optional
     * timestamp) or the record lacks it.
     *
     * @param array<mixed> $record
     */
    private function recordField(array $record, string $key): string
    {
        $field = $this->format->jsonFields[$key] ?? null;

        return $field === null ? '' : self::text(self::field($record, $field));
    }

    /**
     * The named groups of a line the pattern matches, null for any other line. A match that hits
     * the match limit counts as no match.
     *
     * @return null|array<string, string>
     */
    private function groups(string $line): ?array
    {
        if ($line === $this->lastLine) {
            return $this->lastGroups;
        }

        $groups = null;
        if (preg_match((string) $this->format->pattern, substr($line, 0, self::MAX_MATCHED_BYTES), $match) === 1) {
            $groups = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        $this->lastLine = $line;
        $this->lastGroups = $groups;

        return $groups;
    }

    /**
     * A field of a JSON record: the key as written, else a dotted path into nested objects, so
     * `log.level` finds both `{"log.level": …}` and `{"log": {"level": …}}`.
     *
     * @param array<mixed> $record
     */
    private static function field(array $record, string $field): mixed
    {
        if (array_key_exists($field, $record)) {
            return $record[$field];
        }

        $value = $record;
        foreach (explode('.', $field) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
