<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Command;

use Mata\Services\Command\CommandResult;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Services\Command\ShellCommands;
use Mata\Tests\Support\FakeProcessCommandRunner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Command\NativeCommandRunner
 *
 * @internal
 */
final class NativeCommandRunnerTest extends TestCase
{
    public function testRunReturnsStdoutForSuccessfulCommand(): void
    {
        $processRunner = new FakeProcessCommandRunner(new CommandResult('ok', '', 0, false, false, 10));
        $runner = new NativeCommandRunner($processRunner, 3, 100);

        $this->assertSame('ok', $runner->run('echo ok'));
        $this->assertSame([
            'command' => 'echo ok',
            'timeoutSeconds' => 3,
            'maxOutputBytes' => 100,
        ], $processRunner->calls[0]);
    }

    public function testRunReturnsNullWhenCommandTimesOut(): void
    {
        $runner = new NativeCommandRunner(new FakeProcessCommandRunner(new CommandResult('', '', 124, true, false, 1000)));

        $this->assertNull($runner->run('sleep 10'));
    }

    public function testRunReturnsNullWhenOutputIsTruncated(): void
    {
        $runner = new NativeCommandRunner(new FakeProcessCommandRunner(new CommandResult('partial', '', 125, false, true, 10)));

        $this->assertNull($runner->run('yes'));
    }

    public function testCountProcessesTreatsPgrepNoMatchAsZero(): void
    {
        $processRunner = new FakeProcessCommandRunner(new CommandResult('', '', 1, false, false, 10));
        $runner = new NativeCommandRunner($processRunner);

        $this->assertSame(0, $runner->countProcesses('definitely-missing-process'));
        $this->assertSame(ShellCommands::processCount('definitely-missing-process', true), $processRunner->calls[0]['command']);
    }
}
