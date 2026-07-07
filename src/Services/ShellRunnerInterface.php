<?php

declare(strict_types=1);

namespace Mata\Services;

interface ShellRunnerInterface
{
    public function run(string $command): ?string;

    public function countProcesses(string $namePattern, bool $exact = true): int;
}
