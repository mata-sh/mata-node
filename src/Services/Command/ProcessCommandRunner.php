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

        $process = proc_open($command, [
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
        $deadline = $start + $timeoutSeconds;

        while (true) {
            $this->readPipe($pipes[1], $stdout, $stderr, true, $maxOutputBytes, $outputTruncated);
            $this->readPipe($pipes[2], $stdout, $stderr, false, $maxOutputBytes, $outputTruncated);

            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = $status['exitcode'];

                break;
            }

            if ($outputTruncated) {
                proc_terminate($process);
                $exitCode = self::EXIT_OUTPUT_TRUNCATED;

                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                $exitCode = self::EXIT_TIMEOUT;

                break;
            }

            usleep(self::SLEEP_MICROSECONDS);
        }

        $this->readPipe($pipes[1], $stdout, $stderr, true, $maxOutputBytes, $outputTruncated);
        $this->readPipe($pipes[2], $stdout, $stderr, false, $maxOutputBytes, $outputTruncated);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeExitCode = proc_close($process);
        if ($exitCode === null && $closeExitCode >= 0) {
            $exitCode = $closeExitCode;
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

            if ($isStdout) {
                $this->appendOutput($stdout, $stdout, $stderr, $chunk, $maxOutputBytes, $outputTruncated);
            } else {
                $this->appendOutput($stderr, $stdout, $stderr, $chunk, $maxOutputBytes, $outputTruncated);
            }

            if ($outputTruncated) {
                break;
            }
        }
    }

    private function appendOutput(
        string &$target,
        string $stdout,
        string $stderr,
        string $chunk,
        int $maxOutputBytes,
        bool &$outputTruncated,
    ): void {
        $remainingBytes = $maxOutputBytes - strlen($stdout) - strlen($stderr);

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
