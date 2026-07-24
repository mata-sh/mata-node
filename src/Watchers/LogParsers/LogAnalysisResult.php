<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

/**
 * Immutable result of a single log file analysis.
 *
 * Carries the counts previously returned as an array plus the material the
 * app-level fatal cursor is built from. The fatal digest is an opaque
 * hexadecimal SHA-256 string; it is never returned by the API on its own.
 */
final readonly class LogAnalysisResult
{
    /**
     * @param string      $parserName  parser identity contributing to the app aggregate
     * @param int         $fatal       number of fatal (error level) lines
     * @param int         $warnings    number of warning level lines
     * @param int         $total       number of physical lines
     * @param null|string $fatalDigest lowercase SHA-256 of the ordered fatal observation, null when $fatal is 0
     */
    public function __construct(
        public string $parserName,
        public int $fatal = 0,
        public int $warnings = 0,
        public int $total = 0,
        public ?string $fatalDigest = null,
    ) {}

    public static function empty(string $parserName): self
    {
        return new self($parserName);
    }

    /**
     * Counts only, in the historical array shape.
     *
     * @return array{fatal: int, warnings: int, total: int}
     */
    public function counts(): array
    {
        return [
            'fatal' => $this->fatal,
            'warnings' => $this->warnings,
            'total' => $this->total,
        ];
    }
}
