<?php

declare(strict_types=1);

namespace Mata\Services\Command;

class NativeCommandRunner implements CommandRunnerInterface
{
    public function __construct(
        private ProcessCommandRunnerInterface $processRunner = new ProcessCommandRunner(),
        private int $timeoutSeconds = 5,
        private int $maxOutputBytes = ProcessCommandRunner::DEFAULT_MAX_OUTPUT_BYTES,
    ) {}

    public function run(string $command): ?string
    {
        $result = $this->processRunner->run($command, $this->timeoutSeconds, $this->maxOutputBytes);

        if ($result->timedOut || $result->outputTruncated || $result->exitCode > 1) {
            return null;
        }

        return $result->stdout;
    }

    public function countProcesses(string $namePattern, bool $exact = true): int
    {
        return (int) $this->run(ShellCommands::processCount($namePattern, $exact));
    }
}
