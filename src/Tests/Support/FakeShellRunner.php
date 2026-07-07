<?php

declare(strict_types=1);

namespace Mata\Tests\Support;

use Mata\Services\ShellRunnerInterface;

final class FakeShellRunner implements ShellRunnerInterface
{
    /** @var array<int,string> */
    public array $commands = [];

    /** @var array<int,array{namePattern: string, exact: bool}> */
    public array $processCountCalls = [];

    /**
     * @param array<string,null|string> $outputs
     * @param array<string,int>         $processCounts
     */
    public function __construct(
        private array $outputs = [],
        private array $processCounts = []
    ) {}

    public function run(string $command): ?string
    {
        $this->commands[] = $command;

        return $this->outputs[$command] ?? null;
    }

    public function countProcesses(string $namePattern, bool $exact = true): int
    {
        $this->processCountCalls[] = [
            'namePattern' => $namePattern,
            'exact' => $exact,
        ];

        return $this->processCounts[$this->processCountKey($namePattern, $exact)] ?? 0;
    }

    public static function processCountKey(string $namePattern, bool $exact = true): string
    {
        return $namePattern . '|' . ($exact ? 'exact' : 'contains');
    }
}
