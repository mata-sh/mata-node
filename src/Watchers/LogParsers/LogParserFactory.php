<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Mata\Config\Dto\LogFormatConfig;

/**
 * The log parsers of a node: the built-in ones written in PHP, plus the formats defined in YAML
 * (see LogFormatLoader). Only the built-in ones take part in auto-detection.
 */
final class LogParserFactory
{
    /** Parsers written in PHP, by name, in the order auto-detection tries them. */
    public const BUILT_IN = [
        'json' => JsonParser::class,
        'monolog' => MonologParser::class,
        'php_error_log' => PhpErrorLogParser::class,
    ];

    /** @var array<string, class-string<AbstractLogParser>> parser class detected per log file */
    private array $detected = [];

    /**
     * @param array<string, LogFormatConfig> $formats formats defined in YAML, by name
     */
    public function __construct(private readonly array $formats = []) {}

    /**
     * Every name `type` in apps.json can take.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [...array_keys(self::BUILT_IN), ...array_keys($this->formats)];
    }

    /** The parser named by `type` in apps.json; null for an unknown name. Names are case-sensitive. */
    public function parser(string $name): ?LogParserInterface
    {
        if (isset(self::BUILT_IN[$name])) {
            $class = self::BUILT_IN[$name];

            return new $class();
        }

        return isset($this->formats[$name]) ? new DefinedLogParser($this->formats[$name]) : null;
    }

    /**
     * The first built-in parser that claims the file, php_error_log when none does. A detected
     * class is kept per file; the fallback is not, so a file that gets lines is detected again.
     */
    public function detect(string $logFile): LogParserInterface
    {
        $class = $this->detected[$logFile] ?? null;
        if ($class !== null) {
            return new $class();
        }

        foreach (self::BUILT_IN as $class) {
            $parser = new $class();
            if ($parser->detect($logFile)) {
                $this->detected[$logFile] = $class;

                return $parser;
            }
        }

        return new PhpErrorLogParser();
    }
}
