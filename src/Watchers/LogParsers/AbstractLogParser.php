<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use DateTimeImmutable;
use Generator;
use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\NativeCommandRunner;
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
     * as a head plus streamed tail blocks; severity matching continues across
     * them, so counts stay identical to a full-line match at any length.
     */
    protected const MAX_LINE_BUFFER_BYTES = 5 * 1024 * 1024;

    /**
     * Bytes of the previous block replayed when matching a continued line, so
     * a severity marker straddling a block boundary is still found.
     */
    protected const MATCH_OVERLAP_BYTES = 256;

    protected CommandRunnerInterface $commandRunner;

    public function __construct(?CommandRunnerInterface $commandRunner = null)
    {
        $this->commandRunner = $commandRunner ?? new NativeCommandRunner();
    }

    /**
     * Analyze a log file in a single bounded-memory pass.
     *
     * Counts keep the historical line based semantics (one match per physical
     * line, total counts newline terminated lines). The digest covers the
     * complete ordered fatal entries of the file plus its generation identity,
     * so appending warnings never changes it while appending, changing,
     * duplicating or reordering fatal entries always does.
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
     * PCRE matching a physical line that counts as fatal.
     */
    abstract protected function fatalLinePattern(): string;

    /**
     * PCRE matching a physical line that counts as a warning.
     */
    abstract protected function warningLinePattern(): string;

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
     * Whether the severity patterns can only match at the start of a line.
     *
     * Anchored patterns must not be re-run on the continuation of an over-long
     * line: the continuation is not a line start, so a match there would be a
     * false positive.
     */
    protected function severityPatternsMatchLineStartOnly(): bool
    {
        return false;
    }

    protected function shell(string $command): ?string
    {
        return $this->commandRunner->run($command);
    }

    protected function normalizeTimestamp(string $timestamp): string
    {
        try {
            return (new DateTimeImmutable($timestamp))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Group multi-line log entries by entry start pattern.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,string>
     */
    protected function groupMultilineEntries(array $lines, string $entryStartPattern): array
    {
        $logEntries = [];
        $currentEntry = '';

        foreach ($lines as $line) {
            if (preg_match($entryStartPattern, $line)) {
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
        $entryStartPattern = $this->entryStartPattern();
        $fatalPattern = $this->fatalLinePattern();
        $warningPattern = $this->warningLinePattern();

        $rescanContinuations = !$this->severityPatternsMatchLineStartOnly();

        $fatal = 0;
        $warnings = 0;
        $total = 0;
        $fatalEntries = 0;
        $inFatalEntry = false;
        $lineIsFatal = false;
        $lineIsWarning = false;
        $overlap = '';

        $digest = hash_init('sha256');
        hash_update($digest, $this->digestPreamble($generation));

        foreach ($this->readLines($handle) as [$text, $startsLine, $endsLine]) {
            if ($endsLine) {
                ++$total;
            }

            if ($startsLine) {
                $lineIsFatal = preg_match($fatalPattern, $text) === 1;
                $lineIsWarning = preg_match($warningPattern, $text) === 1;
                if ($lineIsFatal) {
                    ++$fatal;
                }
                if ($lineIsWarning) {
                    ++$warnings;
                }

                if ($entryStartPattern === null || preg_match($entryStartPattern, $text) === 1) {
                    $inFatalEntry = $lineIsFatal;
                    if ($inFatalEntry) {
                        ++$fatalEntries;
                        hash_update($digest, "\0entry:" . $fatalEntries . "\0");
                    }
                }
            } elseif ($rescanContinuations) {
                // Tail of a line longer than the buffer: keep looking for a
                // severity marker so counts match a full line match.
                $subject = $overlap . $text;

                if (!$lineIsFatal && preg_match($fatalPattern, $subject) === 1) {
                    $lineIsFatal = true;
                    ++$fatal;
                    ++$fatalEntries;
                    $inFatalEntry = true;
                    hash_update($digest, "\0entry:" . $fatalEntries . "\0");
                }
                if (!$lineIsWarning && preg_match($warningPattern, $subject) === 1) {
                    $lineIsWarning = true;
                    ++$warnings;
                }
            }

            $overlap = $endsLine ? '' : substr($text, -self::MATCH_OVERLAP_BYTES);

            if (!$inFatalEntry) {
                continue;
            }

            $payload = $entryStartPattern === null && $startsLine && $endsLine
                ? $this->canonicalizeEntryLine($text)
                : $text;

            hash_update($digest, "\0" . strlen($payload) . "\0" . $payload);
        }

        hash_update($digest, "\0count:" . $fatal . ':' . $fatalEntries . "\0");

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
