<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use DateTimeImmutable;
use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\NativeCommandRunner;
use Throwable;

/**
 * Base parser helpers for log parsers.
 */
abstract class AbstractLogParser implements LogParserInterface
{
    protected CommandRunnerInterface $commandRunner;

    public function __construct(?CommandRunnerInterface $commandRunner = null)
    {
        $this->commandRunner = $commandRunner ?? new NativeCommandRunner();
    }

    protected function shell(string $command): ?string
    {
        return $this->commandRunner->run($command);
    }

    protected function normalizeTimestamp(string $timestamp): string
    {
        try {
            return (new DateTimeImmutable($timestamp))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Group multi-line log entries by entry start pattern.
     *
     * @param array<int,string> $lines
     *
     * @return array<int,string>
     */
    protected function groupMultilineEntries(array $lines, string $entryStartPattern): array
    {
        $logEntries = [];
        $currentEntry = '';

        foreach ($lines as $line) {
            if (preg_match($entryStartPattern, $line)) {
                if ($currentEntry !== '') {
                    $logEntries[] = $currentEntry;
                }
                $currentEntry = $line;
            } elseif ($currentEntry !== '') {
                $currentEntry .= "\n" . $line;
            }
        }

        if ($currentEntry !== '') {
            $logEntries[] = $currentEntry;
        }

        return $logEntries;
    }
}
