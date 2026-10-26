<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use DateTimeImmutable;
use Generator;
use Throwable;

/**
 * Base parser helpers for log parsers.
 */
abstract class AbstractLogParser implements LogParserInterface
{
    /**
     * Digest scheme version. Bump when the digest input changes so that
     * cursors produced by different node versions are never compared.
     */
    protected const DIGEST_SCHEME = 'mata.fatal.v1';

    /**
     * Physical read granularity. Has no effect on parsing semantics: reads are
     * reassembled into complete logical lines before anything looks at them.
     * `fgets()` allocates its length argument up front, so it stays small.
     */
    protected const READ_BLOCK_BYTES = 65536;

    /**
     * Largest logical line held in memory at once. A longer line is processed
     * as a head plus streamed tail blocks; only the head is typed, as an
     * entry's level sits at its start.
     */
    protected const MAX_LINE_BUFFER_BYTES = 5 * 1024 * 1024;

    /** Lines from the start of a file that detect() looks at. */
    protected const DETECT_LINES = 10;

    /**
     * Whether at least half of the first non-blank lines of a file are format lines. Reads only
     * the start of the file, through readLines(), so neither file size nor line length matters.
     */
    public function detect(string $logFile): bool
    {
        $handle = @fopen($logFile, 'rb');
        if ($handle === false) {
            return false;
        }

        $lines = 0;
        $matched = 0;

        try {
            foreach ($this->readLines($handle) as [$text, $startsLine]) {
                if (!$startsLine || trim($text) === '') {
                    continue;
                }

                ++$lines;
                if ($this->isFormatLine($text)) {
                    ++$matched;
                }

                if ($lines === self::DETECT_LINES) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $lines > 0 && $matched / $lines >= 0.5;
    }

    /**
     * Lines grouped into entries (see startsEntry()), each parsed by parseEntry(); entries it
     * cannot parse are left out.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseLines(array $lines): array
    {
        $entries = $this->entryStartPattern() === null ? $lines : $this->groupEntries($lines);

        return array_values(array_filter(array_map($this->parseEntry(...), $entries)));
    }

    /**
     * Analyze a log file in a single bounded-memory pass.
     *
     * `fatal` and `warnings` count entries by lineType(), `total` counts
     * newline terminated lines. The digest covers the complete ordered fatal
     * entries of the file plus its generation identity, so appending warnings
     * never changes it while appending, changing, duplicating or reordering
     * fatal entries always does.
     */
    public function analyzeLog(string $logFile): LogAnalysisResult
    {
        // Rotation may have replaced the file since an earlier stat in this process.
        clearstatcache(true, $logFile);

        if (!file_exists($logFile) || !is_readable($logFile)) {
            return LogAnalysisResult::empty($this->getName());
        }

        $handle = @fopen($logFile, 'rb');
        if ($handle === false) {
            return LogAnalysisResult::empty($this->getName());
        }

        try {
            return $this->scan($handle, $this->fileGeneration($handle));
        } finally {
            fclose($handle);
        }
    }

    /**
     * The type of the entry a line starts: `Error` and `Warning` are counted, anything else is
     * not. parseEntry() gives an entry the same type, so the counts match the parsed entries.
     */
    abstract protected function lineType(string $line): string;

    /** Whether a line looks like this format, for detect(). */
    abstract protected function isFormatLine(string $line): bool;

    /**
     * One entry as `timestamp`, `type`, `message` and any extra fields; null when it is not one.
     *
     * @return null|array<string,mixed>
     */
    abstract protected function parseEntry(string $entry): ?array;

    /**
     * PCRE matching the first line of a log entry.
     *
     * Null for line delimited formats where every line is its own entry.
     */
    protected function entryStartPattern(): ?string
    {
        return null;
    }

    /**
     * Whether a physical line starts a log entry; every line does in line delimited formats.
     */
    protected function startsEntry(string $line): bool
    {
        $pattern = $this->entryStartPattern();

        return $pattern === null || preg_match($pattern, $line) === 1;
    }

    /**
     * Canonical digest representation of one complete single line entry.
     *
     * Used by line delimited formats whose serialization is not stable (JSON
     * object key order). Only called for a complete entry, that is when the
     * line did not exceed the line buffer.
     */
    protected function canonicalizeEntryLine(string $line): string
    {
        return $line;
    }

    /**
     * A JSON record with its keys sorted, so the digest does not depend on key order.
     *
     * Falls back to the raw line when the record is not decodable, which keeps
     * malformed input contributing its own bytes instead of being dropped.
     */
    protected function canonicalJsonLine(string $line): string
    {
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) {
            return $line;
        }

        $canonical = json_encode(
            self::sortRecursively($decoded),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $canonical === false ? $line : $canonical;
    }

    /** The timestamp as `Y-m-d H:i:s`; '' when it is empty or not a date. */
    protected function normalizeTimestamp(string $timestamp): string
    {
        if (trim($timestamp) === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($timestamp))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Lines joined into multi-line entries: a line that starts an entry plus the lines up to the
     * next one. Lines before the first entry are dropped.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,string>
     */
    private function groupEntries(array $lines): array
    {
        $logEntries = [];
        $currentEntry = '';

        foreach ($lines as $line) {
            if ($this->startsEntry($line)) {
                if ($currentEntry !== '') {
                    $logEntries[] = $currentEntry;
                }
                $currentEntry = $line;
            } elseif ($currentEntry !== '') {
                $currentEntry .= "\n" . $line;
            }
        }

        if ($currentEntry !== '') {
            $logEntries[] = $currentEntry;
        }

        return $logEntries;
    }

    /**
     * @param resource $handle
     */
    private function scan($handle, string $generation): LogAnalysisResult
    {
        $lineDelimited = $this->entryStartPattern() === null;

        $fatal = 0;
        $warnings = 0;
        $total = 0;
        $inFatalEntry = false;

        $digest = hash_init('sha256');
        hash_update($digest, $this->digestPreamble($generation));

        foreach ($this->readLines($handle) as [$text, $startsLine, $endsLine]) {
            if ($endsLine) {
                ++$total;
            }

            if ($startsLine && $this->startsEntry($text)) {
                $type = $this->lineType($text);
                $inFatalEntry = $type === 'Error';
                if ($inFatalEntry) {
                    ++$fatal;
                    hash_update($digest, "\0entry:" . $fatal . "\0");
                } elseif ($type === 'Warning') {
                    ++$warnings;
                }
            }

            if (!$inFatalEntry) {
                continue;
            }

            $payload = $lineDelimited && $startsLine && $endsLine
                ? $this->canonicalizeEntryLine($text)
                : $text;

            hash_update($digest, "\0" . strlen($payload) . "\0" . $payload);
        }

        // v1 counted fatal lines and fatal entries apart; both are the entry count now, and the
        // layout stays so that cursors of logs whose entries kept their type do not change.
        hash_update($digest, "\0count:" . $fatal . ':' . $fatal . "\0");

        return new LogAnalysisResult(
            parserName: $this->getName(),
            fatal: $fatal,
            warnings: $warnings,
            total: $total,
            fatalDigest: $fatal > 0 ? hash_final($digest) : null,
        );
    }

    /**
     * Read a stream as complete logical lines.
     *
     * Physical reads are small and reassembled, so a caller always sees a whole
     * line unless it exceeds MAX_LINE_BUFFER_BYTES. Such a line is yielded as a
     * head followed by its tail blocks, which keeps peak memory at one buffer.
     *
     * Yields [text without the trailing newline, text starts a line, text ends
     * a line].
     *
     * @param resource $handle
     *
     * @return Generator<int, array{0: string, 1: bool, 2: bool}>
     */
    private function readLines($handle): Generator
    {
        $buffer = '';
        $buffering = true;

        while (($block = fgets($handle, self::READ_BLOCK_BYTES)) !== false) {
            $endsLine = str_ends_with($block, "\n");
            $text = $endsLine ? substr($block, 0, -1) : $block;

            if (!$buffering) {
                yield [$text, false, $endsLine];
                $buffering = $endsLine;

                continue;
            }

            $buffer .= $text;

            if ($endsLine) {
                yield [$buffer, true, true];
                $buffer = '';

                continue;
            }

            if (strlen($buffer) >= self::MAX_LINE_BUFFER_BYTES) {
                yield [$buffer, true, false];
                $buffer = '';
                $buffering = false;
            }
        }

        if ($buffer !== '') {
            yield [$buffer, true, false];
        }
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursively($item);
            }
        }

        if (!$isList) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function digestPreamble(string $generation): string
    {
        return self::DIGEST_SCHEME . "\0" . $this->getName() . "\0" . $generation . "\0";
    }

    /**
     * Non-secret file generation identity.
     *
     * Device and inode change when rotation replaces the file, but not when
     * the file is merely appended to, so warning-only writes leave the digest
     * untouched. Never returned by the API.
     *
     * Taken from the open handle, not the path: rotation between opening and
     * stating would otherwise pair the bytes of the old file with the identity
     * of the new one and fake a cursor change.
     *
     * @param resource $handle
     */
    private function fileGeneration($handle): string
    {
        $stat = @fstat($handle);
        if ($stat === false) {
            return 'unknown';
        }

        return ((int) ($stat['dev'] ?? 0)) . ':' . ((int) ($stat['ino'] ?? 0));
    }
}
