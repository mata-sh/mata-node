<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Utils\StatusLevel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Output conventions shared with the dashboard's bin/mata. Every command ends in a StatusLevel:
 * its value is the exit code and its label the `status` of the --json document, which carries a
 * schema_version. With --json every outcome, failures included, is a JSON document.
 */
final class CliOutput
{
    /**
     * Written raw: the formatter would strip `<info>`-like text from values.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode(
            ['schema_version' => 1] + $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Reports a failure as `{"status":…,"error":…}` with --json, else as an error line.
     *
     * @param array<string, mixed> $payload extra fields for the JSON document
     */
    public static function fail(
        InputInterface $input,
        OutputInterface $output,
        string $message,
        StatusLevel $level = StatusLevel::Error,
        array $payload = [],
    ): int {
        if ($input->hasOption('json') && $input->getOption('json')) {
            self::json($output, ['status' => $level->label(), 'error' => $message] + $payload);
        } else {
            $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');
        }

        return $level->value;
    }

    /** The level as a coloured symbol, for list lines. */
    public static function mark(StatusLevel $level): string
    {
        return match ($level) {
            StatusLevel::Ok => '<info>✓</info>',
            StatusLevel::Warning => '<comment>!</comment>',
            StatusLevel::Error => '<error>✗</error>',
        };
    }

    /** The level as a coloured word. */
    public static function label(StatusLevel $level): string
    {
        return match ($level) {
            StatusLevel::Ok => '<info>ok</info>',
            StatusLevel::Warning => '<comment>warning</comment>',
            StatusLevel::Error => '<error>error</error>',
        };
    }
}
