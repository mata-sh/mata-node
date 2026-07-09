<?php

declare(strict_types=1);

namespace Mata\Services\Command;

final readonly class CommandResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut,
        public bool $outputTruncated,
        public int $durationMs,
    ) {}

    public function successful(): bool
    {
        return !$this->timedOut && !$this->outputTruncated && $this->exitCode === 0;
    }
}
