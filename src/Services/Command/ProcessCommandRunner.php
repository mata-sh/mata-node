<?php

declare(strict_types=1);

namespace Mata\Services\Command;

final class ProcessCommandRunner implements ProcessCommandRunnerInterface
{
    private const EXIT_START_FAILED = 127;
    private const EXIT_TIMEOUT = 124;
    private const EXIT_OUTPUT_TRUNCATED = 125;
    private const READ_BYTES = 8192;
    private const SLEEP_MICROSECONDS = 50_000;
    private const TERMINATE_GRACE_MICROSECONDS = 1_000_000;
    private const KILL_GRACE_MICROSECONDS = 500_000;
    private const SIGNAL_TERM = 15;
    private const SIGNAL_KILL = 9;

    public const DEFAULT_TIMEOUT_SECONDS = 10;
    public const DEFAULT_MAX_OUTPUT_BYTES = 5_242_880;

    public function run(
        string $command,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
    ): CommandResult {
        $start = microtime(true);

        if (
            $timeoutSeconds < 1
            || $maxOutputBytes < 1
            || !function_exists('proc_open')
            || !function_exists('proc_get_status')
            || !function_exists('proc_terminate')
            || !function_exists('proc_close')
        ) {
            return new CommandResult('', 'Failed to start process', self::EXIT_START_FAILED, false, false, 0);
        }

        $usesProcessGroup = $this->canUseProcessGroups();
        $process = proc_open($this->prepareCommand($command, $usesProcessGroup), [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return new CommandResult('', 'Failed to start process', self::EXIT_START_FAILED, false, false, 0);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $timedOut = false;
        $outputTruncated = false;
        $processStopped = false;
        $deadline = $start + $timeoutSeconds;

        while (true) {
            $this->readPipe($pipes[1], $stdout, $stderr, true, $maxOutputBytes, $outputTruncated);
            $this->readPipe($pipes[2], $stdout, $stderr, false, $maxOutputBytes, $outputTruncated);

            $status = proc_get_status($process);

            if (!$status['running']) {
                $processStopped = true;
                $exitCode = $status['exitcode'];

                break;
            }

            if ($outputTruncated) {
                $processStopped = $this->terminateProcess($process, $usesProcessGroup);
                $exitCode = self::EXIT_OUTPUT_TRUNCATED;

                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                $processStopped = $this->terminateProcess($process, $usesProcessGroup);
                $exitCode = self::EXIT_TIMEOUT;

                break;
            }

            usleep(self::SLEEP_MICROSECONDS);
        }

        $this->readPipe($pipes[1], $stdout, $stderr, true, $maxOutputBytes, $outputTruncated);
        $this->readPipe($pipes[2], $stdout, $stderr, false, $maxOutputBytes, $outputTruncated);

        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($processStopped || !proc_get_status($process)['running']) {
            $closeExitCode = proc_close($process);
            if ($exitCode === null && $closeExitCode >= 0) {
                $exitCode = $closeExitCode;
            }
        }

        if ($outputTruncated && !$timedOut) {
            $exitCode = self::EXIT_OUTPUT_TRUNCATED;
        }

        return new CommandResult(
            $stdout,
            $stderr,
            $exitCode ?? self::EXIT_START_FAILED,
            $timedOut,
            $outputTruncated,
            (int) ((microtime(true) - $start) * 1000),
        );
    }

    private function prepareCommand(string $command, bool $usesProcessGroup): string
    {
        if (!$usesProcessGroup) {
            return $command;
        }

        $setsidPath = $this->setsidPath();
        if ($setsidPath === null) {
            return $command;
        }

        return 'exec ' . escapeshellarg($setsidPath) . ' sh -c ' . escapeshellarg($command);
    }

    private function canUseProcessGroups(): bool
    {
        return function_exists('posix_kill') && $this->setsidPath() !== null;
    }

    private function setsidPath(): ?string
    {
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param resource $process
     */
    private function terminateProcess(mixed $process, bool $usesProcessGroup): bool
    {
        $this->signalProcess($process, $usesProcessGroup, self::SIGNAL_TERM);
        if ($this->waitForExit($process, self::TERMINATE_GRACE_MICROSECONDS)) {
            return true;
        }

        $this->signalProcess($process, $usesProcessGroup, self::SIGNAL_KILL);

        return $this->waitForExit($process, self::KILL_GRACE_MICROSECONDS);
    }

    /**
     * @param resource $process
     */
    private function signalProcess(mixed $process, bool $usesProcessGroup, int $signal): void
    {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }

        $pid = (int) $status['pid'];
        if ($pid < 1) {
            return;
        }

        if ($usesProcessGroup && function_exists('posix_kill') && @posix_kill(-$pid, $signal)) {
            return;
        }

        proc_terminate($process, $signal);
    }

    /**
     * @param resource $process
     */
    private function waitForExit(mixed $process, int $timeoutMicroseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMicroseconds / 1_000_000);

        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return true;
            }

            usleep(self::SLEEP_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @param resource $pipe
     */
    private function readPipe(
        mixed $pipe,
        string &$stdout,
        string &$stderr,
        bool $isStdout,
        int $maxOutputBytes,
        bool &$outputTruncated,
    ): void {
        while (!feof($pipe)) {
            $chunk = fread($pipe, self::READ_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $usedBytes = strlen($stdout) + strlen($stderr);
            if ($isStdout) {
                $this->appendOutput($stdout, $usedBytes, $chunk, $maxOutputBytes, $outputTruncated);
            } else {
                $this->appendOutput($stderr, $usedBytes, $chunk, $maxOutputBytes, $outputTruncated);
            }

            if ($outputTruncated) {
                break;
            }
        }
    }

    private function appendOutput(
        string &$target,
        int $usedBytes,
        string $chunk,
        int $maxOutputBytes,
        bool &$outputTruncated,
    ): void {
        $remainingBytes = $maxOutputBytes - $usedBytes;

        if ($remainingBytes <= 0) {
            $outputTruncated = true;

            return;
        }

        if (strlen($chunk) > $remainingBytes) {
            $target .= substr($chunk, 0, $remainingBytes);
            $outputTruncated = true;

            return;
        }

        $target .= $chunk;
    }
}
