<?php

declare(strict_types=1);

namespace Mata\Tests\Support;

use Mata\Services\Command\CommandResult;
use Mata\Services\Command\ProcessCommandRunner;
use Mata\Services\Command\ProcessCommandRunnerInterface;

final class FakeProcessCommandRunner implements ProcessCommandRunnerInterface
{
    /** @var array<int,array{command: string, timeoutSeconds: int, maxOutputBytes: int}> */
    public array $calls = [];

    public function __construct(
        private CommandResult $result,
    ) {}

    public function run(
        string $command,
        int $timeoutSeconds = ProcessCommandRunner::DEFAULT_TIMEOUT_SECONDS,
        int $maxOutputBytes = ProcessCommandRunner::DEFAULT_MAX_OUTPUT_BYTES,
    ): CommandResult {
        $this->calls[] = [
            'command' => $command,
            'timeoutSeconds' => $timeoutSeconds,
            'maxOutputBytes' => $maxOutputBytes,
        ];

        return $this->result;
    }
}
