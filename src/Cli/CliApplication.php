<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Utils\StatusLevel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Every failure exits 2 (error); Symfony would exit 1, which means warning here. Every failure,
 * usage errors included, honours --json; without it usage errors keep Symfony's rendering, and
 * -v adds the trace.
 */
final class CliApplication extends Application
{
    public function __construct()
    {
        parent::__construct('mata node');
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ExceptionInterface $exception) {
            // The input may not be bound yet (unknown option, missing argument), so read the raw token.
            if ($input->hasParameterOption('--json', true)) {
                CliOutput::json($output, ['status' => StatusLevel::Error->label(), 'error' => $exception->getMessage()]);
            } else {
                $this->renderThrowable($exception, self::errorOutput($output));
            }

            return StatusLevel::Error->value;
        }
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRunCommand($command, $input, $output);
        } catch (ExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($output->isVerbose()) {
                $this->renderThrowable($exception, self::errorOutput($output));
            }

            return CliOutput::fail($input, $output, $exception->getMessage());
        }
    }

    private static function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
