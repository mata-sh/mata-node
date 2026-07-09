<?php

declare(strict_types=1);

namespace Mata\Services\Command;

interface ProcessCommandRunnerInterface
{
    /**
     * Runs a shell command with stdout/stderr capture.
     *
     * If outputTruncated is true, the command was killed before normal completion.
     */
    public function run(
        string $command,
        int $timeoutSeconds = ProcessCommandRunner::DEFAULT_TIMEOUT_SECONDS,
        int $maxOutputBytes = ProcessCommandRunner::DEFAULT_MAX_OUTPUT_BYTES,
    ): CommandResult;
}
