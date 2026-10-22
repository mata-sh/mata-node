<?php

declare(strict_types=1);

namespace Mata\Config;

use Mata\Config\Dto\LoggingConfig;
use Mata\Utils\PathResolver;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use UnhandledMatchError;

final class LogConfigurator
{
    public function createLogger(string $channel, string $path, Level $level): Logger
    {
        $logger = new Logger($channel);
        $this->applyHandler($logger, $path, $level);

        return $logger;
    }

    public function createBootLogger(PathResolver $paths, string $channel = 'boot'): Logger
    {
        $path = $paths->bootLogPath;

        return $this->createLogger($channel, $path, Level::Error);
    }

    public function createCronLogger(PathResolver $paths, string $jobName, string $channel = 'cronjob'): Logger
    {
        $path = $paths->logsPath . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . "app-{$jobName}.log";

        return $this->createLogger($channel, $path, Level::Debug);
    }

    public function applyConfiguredLogger(Logger $logger, LoggingConfig $config, PathResolver $paths): void
    {
        $level = $this->parseLogLevel($config->level);
        $this->applyHandler($logger, $paths->appLogPath, $level ?? Level::Debug);

        // Only now: before applyHandler() the logger has no handler to write the warning to.
        if ($level === null) {
            $logger->warning('Invalid monolog level configured, falling back to debug', [
                'level' => $config->level,
            ]);
        }
    }

    public function createConfiguredLogger(
        LoggingConfig $config,
        PathResolver $paths,
        string $channel = 'app'
    ): Logger {
        $logger = new Logger($channel);
        $this->applyConfiguredLogger($logger, $config, $paths);

        return $logger;
    }

    private function applyHandler(Logger $logger, string $path, Level $level): void
    {
        $handler = new StreamHandler($path, $level);
        $handler->setFormatter($this->createFormatter());
        $logger->setHandlers([$handler]);
    }

    private function createFormatter(): LineFormatter
    {
        $dateFormat = 'Y-m-d H:i:s';
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";

        return new LineFormatter($output, $dateFormat, true, true);
    }

    private function parseLogLevel(string $level): ?Level
    {
        try {
            return Level::fromName(trim($level));
        } catch (UnhandledMatchError) {
            return null;
        }
    }
}
