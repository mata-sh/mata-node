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
    public function createBootLogger(PathResolver $paths, string $channel = 'boot'): Logger
    {
        return $this->createLogger($channel, $paths->bootLogPath, Level::Error);
    }

    public function createConfiguredLogger(
        LoggingConfig $config,
        PathResolver $paths,
        string $channel = 'app'
    ): Logger {
        $level = $this->parseLogLevel($config->level);
        $logger = $this->createLogger($channel, $paths->appLogPath, $level ?? Level::Debug);

        if ($level === null) {
            $logger->warning('Invalid monolog level configured, falling back to debug', [
                'level' => $config->level,
            ]);
        }

        return $logger;
    }

    private function createLogger(string $channel, string $path, Level $level): Logger
    {
        $handler = new StreamHandler($path, $level);
        $handler->setFormatter(new LineFormatter(
            format: "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            dateFormat: 'Y-m-d H:i:s',
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true,
        ));

        return new Logger($channel, [$handler]);
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
