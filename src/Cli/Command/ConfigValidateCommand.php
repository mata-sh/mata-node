<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliOutput;
use Mata\Config\Validation\ConfigValidationReport;
use Mata\Config\Validation\NodeConfigValidator;
use Mata\Utils\PathResolver;
use Mata\Utils\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:validate', description: 'Validate config/ with the loaders the node uses at startup')]
final class ConfigValidateCommand extends Command
{
    public function __construct(
        private readonly NodeConfigValidator $validator,
        private readonly PathResolver $paths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON')
            ->setHelp(<<<'HELP'
                Checks config/ the way the node reads it at startup and per request, reports every
                problem instead of stopping at the first, and never creates or changes a file.

                Errors make the node answer 503 (missing or invalid config.env, apps.json,
                databases.json, transmit.json) or make /apps fail with 500 (an invalid app entry
                in apps.json; one invalid app fails /apps for all of them). Warnings are settings
                the node skips or replaces with a fallback, such as a malformed log entry or an
                unknown log type, and app directories this user cannot read.

                transmit.json is only checked for being a JSON object. Disabled apps are not checked.

                Exit codes: 0 valid, 1 warnings only, 2 errors. Run as the web server user: a file
                it cannot read fails the node even when your own user can read it.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->validator->validate($this->paths->appPath . '/config', $this->paths->rootPath);

        if ($input->getOption('json')) {
            CliOutput::json($output, $report->jsonSerialize());
        } else {
            $this->writeHuman($output, $report);
        }

        return $report->level()->value;
    }

    private function writeHuman(OutputInterface $output, ConfigValidationReport $report): void
    {
        $output->writeln("Config: {$report->configPath}");
        foreach ($report->files as $file) {
            $output->writeln('  ' . CliOutput::mark($report->fileLevel($file)) . " {$file}");
        }
        foreach ($report->problems as $problem) {
            $text = OutputFormatter::escape("[{$problem->location()}] {$problem->message}");
            $output->writeln('  ' . CliOutput::label($problem->level) . " {$text}");
        }

        $output->writeln(match ($report->level()) {
            StatusLevel::Error => '<error>Invalid configuration: fix the errors above.</error>',
            StatusLevel::Warning => '<comment>Valid with warnings.</comment>',
            StatusLevel::Ok => '<info>Valid.</info>',
        });
    }
}
