<?php

declare(strict_types=1);

namespace Mata\Services\Command;

interface CommandRunnerInterface
{
    public function run(string $command): ?string;

    public function countProcesses(string $namePattern, bool $exact = true): int;
}
