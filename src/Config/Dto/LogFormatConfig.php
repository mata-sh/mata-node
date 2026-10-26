<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * A log format defined in YAML, as LogFormatLoader reads it: either a pattern for the first
 * line of an entry or the fields of a JSON record, plus the levels that are errors and warnings.
 */
final readonly class LogFormatConfig
{
    /**
     * @param null|string                                                        $pattern    compiled PCRE with named groups, for line formats
     * @param null|array{timestamp: null|string, level: string, message: string} $jsonFields record fields, for JSON formats
     * @param array<string, 'Error'|'Warning'>                                   $levels     lowercased level => entry type
     */
    public function __construct(
        public string $name,
        public ?string $pattern,
        public ?array $jsonFields,
        public array $levels,
    ) {}

    /** `Error`, `Warning` or `Other`; levels compare without case and surrounding space. */
    public function type(string $level): string
    {
        return $this->levels[strtolower(trim($level))] ?? 'Other';
    }
}
