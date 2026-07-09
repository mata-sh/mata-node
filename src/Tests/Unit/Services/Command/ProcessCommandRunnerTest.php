<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Command;

use Mata\Services\Command\ProcessCommandRunner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Command\CommandResult
 * @covers \Mata\Services\Command\ProcessCommandRunner
 *
 * @internal
 */
final class ProcessCommandRunnerTest extends TestCase
{
    public function testRunCapturesSuccessfulStdout(): void
    {
        $result = (new ProcessCommandRunner())->run($this->phpCommand('echo "ok";'), 2);

        $this->assertTrue($result->successful());
        $this->assertSame('ok', $result->stdout);
        $this->assertSame('', $result->stderr);
        $this->assertSame(0, $result->exitCode);
        $this->assertFalse($result->timedOut);
        $this->assertFalse($result->outputTruncated);
    }

    public function testRunCapturesNonZeroExitCodeAndStderr(): void
    {
        $result = (new ProcessCommandRunner())->run($this->phpCommand('fwrite(STDERR, "bad"); exit(7);'), 2);

        $this->assertSame('', $result->stdout);
        $this->assertSame('bad', $result->stderr);
        $this->assertSame(7, $result->exitCode);
        $this->assertFalse($result->successful());
    }

    public function testRunTimesOut(): void
    {
        $startedAt = microtime(true);
        $result = (new ProcessCommandRunner())->run($this->phpCommand('sleep(3);'), 1);

        $this->assertTrue($result->timedOut);
        $this->assertSame(124, $result->exitCode);
        $this->assertLessThan(2.5, microtime(true) - $startedAt);
    }

    public function testRunStopsWhenOutputExceedsMaximumSize(): void
    {
        $result = (new ProcessCommandRunner())->run($this->phpCommand('echo str_repeat("A", 2048);'), 2, 1024);

        $this->assertTrue($result->outputTruncated);
        $this->assertSame(125, $result->exitCode);
        $this->assertSame(1024, strlen($result->stdout) + strlen($result->stderr));
    }

    private function phpCommand(string $code): string
    {
        return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    }
}
