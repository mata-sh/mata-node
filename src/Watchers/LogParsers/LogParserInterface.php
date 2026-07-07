<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

interface LogParserInterface
{
    public function getName(): string;

    public function detect(string $logFile): bool;

    /**
     * @param array<int,string> $lines
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseLines(array $lines): array;

    /**
     * @return array{fatal: int, warnings: int, total: int}
     */
    public function analyzeLog(string $logFile): array;
}
