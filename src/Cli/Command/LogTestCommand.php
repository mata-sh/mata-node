<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Bootstrap\Runtime;
use Mata\Cli\CliOutput;
use Mata\Config\StartupConfigException;
use Mata\Utils\PathResolver;
use Mata\Utils\StatusLevel;
use Mata\Watchers\Exceptions\AppWatcherException;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'log:test', description: 'Show how the node parses an app\'s configured logs')]
final class LogTestCommand extends Command
{
    private const DEFAULT_LIMIT = 5;
    private const MESSAGE_WIDTH = 160;

    public function __construct(private readonly PathResolver $paths)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('app', InputArgument::REQUIRED, 'App id from apps.json')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most recent parsed entries to show per file', (string) self::DEFAULT_LIMIT)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
            ->setHelp(<<<'HELP'
                For each log file of the app, shows the parser the node uses (the configured type
                or the auto-detected one), how many entries it parses from the lines /logs scans,
                the fatal and warning counts /apps reports, and the most recent entries.

                Use it after adding a log or changing its type: a parser that finds no entries in
                a non-empty file does not match the format. Works with monitoring.loganalysis off.
                Files in a configured directory are listed but not parsed, as by the node.

                Exit codes: 0 every file parsed, 1 warnings (a file is missing or unreadable, a
                type is unknown, a parser found no entries, no log is configured), 2 errors (the
                config is invalid or the app is unknown). Run as the web server user, which must
                be able to read the logs.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = (string) $input->getArgument('app');
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($limit === false) {
            return CliOutput::fail($input, $output, '--limit must be a whole number of at least 0');
        }

        $logger = new NullLogger();

        try {
            $result = Runtime::boot($this->paths, $logger, createMissingConfig: false)
                ->appWatcher($logger)
                ->diagnoseAppLogs($app, $limit);
        } catch (StartupConfigException $e) {
            return CliOutput::fail($input, $output, "{$e->getMessage()}. Run `config validate` for details.");
        } catch (AppWatcherException $e) {
            $message = $e->getHttpStatus() === 404
                ? "Unknown app '{$app}': not in apps.json, or disabled"
                : "{$e->getMessage()} for '{$app}'. Run `config validate` for details.";

            return CliOutput::fail($input, $output, $message);
        }

        $files = [];
        foreach ($result['files'] as $file) {
            $problems = self::problems($file);
            $status = $problems === [] ? StatusLevel::Ok->label() : StatusLevel::Warning->label();
            $files[] = array_merge(
                ['status' => $status],
                $file,
                ['problems' => $problems]
            );

        }

        $problems = $files === [] ? ['No log files are configured, or none exist in the configured directories'] : [];
        $hasFileProblems = false;
        foreach ($files as $file) {
            if (!empty($file['problems'])) {
                $hasFileProblems = true;

                break;
            }
        }
        $warned = !empty($problems) || $hasFileProblems;

        $level = $warned ? StatusLevel::Warning : StatusLevel::Ok;

        if ($input->getOption('json')) {
            CliOutput::json($output, [
                'status' => $level->label(),
                'app' => $result['app'],
                'loganalysis' => $result['loganalysis'],
                'files' => $files,
                'problems' => $problems,
            ]);
        } else {
            $this->writeHuman($output, $result['app'], $result['loganalysis'], $files, $problems);
        }

        return $level->value;
    }

    /**
     * Every problem only warns: the node keeps serving the app and reports what it can parse.
     *
     * @param array<string, mixed> $file
     *
     * @return list<string>
     */
    private static function problems(array $file): array
    {
        if (!$file['readable']) {
            return ["Not found, or not readable by this user: {$file['file']}"];
        }

        if (!$file['parsed']) {
            return [];
        }

        if ($file['error'] !== null) {
            return ["Parsing failed: {$file['error']}"];
        }

        $problems = [];
        if (!$file['type_known']) {
            $problems[] = "Unknown type \"{$file['type']}\"; the node auto-detected {$file['parser']} instead";
        }

        if ($file['lines'] > 0 && $file['entries'] === 0) {
            $problems[] = "{$file['parser']} found no entries in the last {$file['lines']} lines; the type does not match the format";
        }

        return $problems;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param list<string>               $problems
     */
    private function writeHuman(OutputInterface $output, string $app, bool $loganalysis, array $files, array $problems): void
    {
        $output->writeln("App: {$app}" . ($loganalysis ? '' : ' <comment>(monitoring.loganalysis is off: /apps does not parse these logs)</comment>'));

        foreach ($files as $file) {
            $level = $file['problems'] === [] ? StatusLevel::Ok : StatusLevel::Warning;

            // Main file line, bold/stand out for easy scanning
            $output->writeln('');
            $output->writeln(
                sprintf(
                    '  %s <info>%s</info>',
                    CliOutput::mark($level),
                    OutputFormatter::escape($file['path'])
                )
            );
            $summary = trim(self::summary($file));
            if ($summary !== '') {
                $output->writeln('      ' . OutputFormatter::escape($summary));
            }

            foreach ($file['problems'] as $problem) {
                $output->writeln('      ' . CliOutput::label($level) . ' ' . OutputFormatter::escape($problem));
            }

            foreach ($file['samples'] as $entry) {
                $output->writeln('      <fg=gray>' . OutputFormatter::escape(self::entryLine($entry)) . '</>');
            }
        }
        $output->writeln(''); // Spacer after files block

        foreach ($problems as $problem) {
            $output->writeln('  ' . CliOutput::label(StatusLevel::Warning) . ' ' . OutputFormatter::escape($problem));
        }
    }

    /**
     * @param array<string, mixed> $file
     */
    private static function summary(array $file): string
    {
        if (!$file['readable'] || $file['error'] !== null) {
            return '';
        }

        if (!$file['parsed']) {
            return '<comment>(in a configured directory: sized, not parsed)</comment>';
        }

        $parser = $file['type'] === '' ? "{$file['parser']}, detected" : (string) $file['parser'];

        return OutputFormatter::escape("({$parser}) {$file['entries']} entries in the last {$file['lines']} lines; whole file: fatal {$file['fatal']}, warnings {$file['warnings']}");
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function entryLine(array $entry): string
    {
        $message = strtok((string) ($entry['message'] ?? ''), "\n");
        $type = $entry['type'] ?? '?';
        $timestamp = $entry['timestamp'] ?? '';
        $msg = $message === false ? '' : $message;

        $line = sprintf(
            "[Type]     %s\n[Time]     %s\n[Message]  %s",
            $type,
            $timestamp,
            $msg
        );


        return mb_strimwidth($line, 0, self::MESSAGE_WIDTH, '…');
    }
}
