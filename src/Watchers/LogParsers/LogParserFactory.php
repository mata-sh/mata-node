<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Mata\Services\Command\CommandRunnerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * Factory for auto-discovering and managing log parsers.
 *
 * Auto-discovers all *Parser.php files in the LogParsers directory.
 * Caches detection results for performance.
 */
class LogParserFactory
{
    /** @var null|array<string,class-string<LogParserInterface>> Cached parsers: name => class name; classes accept ?CommandRunnerInterface */
    private static ?array $parserClassCache = null;

    /** @var array<string,class-string<LogParserInterface>> Detection cache: logFile => parser class name */
    private static array $detectionCache = [];

    /**
     * Auto-discover all parser classes in LogParsers directory.
     *
     * @param null|LoggerInterface $logger Logger for reporting parsers that fail to load
     *
     * @return array<string,class-string<LogParserInterface>> Parser map: name => class name
     */
    private static function getParserClasses(?LoggerInterface $logger = null): array
    {
        if (self::$parserClassCache !== null) {
            return self::$parserClassCache;
        }

        $parserClasses = [];
        $parserDir = __DIR__;
        $files = glob("{$parserDir}/*Parser.php");

        if ($files === false) {
            $files = [];
        }

        foreach ($files as $file) {
            $className = basename($file, '.php');
            $fullClass = "Mata\\Watchers\\LogParsers\\{$className}";

            // Skip the factory itself
            if ($className === 'LogParserFactory') {
                continue;
            }

            if (!class_exists($fullClass)) {
                continue;
            }

            $reflection = new ReflectionClass($fullClass);
            if (!$reflection->isInstantiable()) {
                continue;
            }

            try {
                $probe = new $fullClass();
                if ($probe instanceof LogParserInterface && !isset($parserClasses[$probe->getName()])) {
                    // @var class-string<LogParserInterface> $fullClass
                    $parserClasses[$probe->getName()] = $fullClass;
                }
            } catch (Throwable $e) {
                $logger?->warning('Failed to load parser', [
                    'class' => $className,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        self::$parserClassCache = $parserClasses;

        return $parserClasses;
    }

    /**
     * Create a parser instance.
     *
     * Every discovered class must accept `?CommandRunnerInterface` as its sole
     * constructor argument (guaranteed today by extending AbstractLogParser).
     *
     * @param class-string<LogParserInterface> $parserClass
     */
    private static function makeParser(
        string $parserClass,
        ?CommandRunnerInterface $commandRunner = null
    ): LogParserInterface {
        return new $parserClass($commandRunner);
    }

    /**
     * Auto-detect log format from file content.
     *
     * Tries each parser's detect() method and returns first match.
     * Results are cached per file path.
     *
     * @param string               $logFile Path to log file
     * @param null|LoggerInterface $logger  Logger for reporting parsers that fail to load
     *
     * @return LogParserInterface Parser instance (defaults to PhpErrorLogParser if no match)
     */
    public static function detectFormat(
        string $logFile,
        ?LoggerInterface $logger = null,
        ?CommandRunnerInterface $commandRunner = null
    ): LogParserInterface {
        // Check cache first
        if (isset(self::$detectionCache[$logFile])) {
            return self::makeParser(self::$detectionCache[$logFile], $commandRunner);
        }

        $parserClasses = self::getParserClasses($logger);

        // Try each parser's detect() method
        foreach ($parserClasses as $parserClass) {
            $parser = self::makeParser($parserClass, $commandRunner);
            if ($parser->detect($logFile)) {
                self::$detectionCache[$logFile] = $parserClass;

                return $parser;
            }
        }

        // Fallback to PHP error_log parser (backward compatibility)
        return new PhpErrorLogParser($commandRunner);
    }

    /**
     * Get parser by format name.
     *
     * Used when a structured log group specifies `type`.
     *
     * @param string               $formatName Format name ('php_error_log', 'monolog', 'json')
     * @param null|LoggerInterface $logger     Logger for reporting parsers that fail to load
     *
     * @return null|LogParserInterface Parser instance or null if not found
     */
    public static function getParser(
        string $formatName,
        ?LoggerInterface $logger = null,
        ?CommandRunnerInterface $commandRunner = null
    ): ?LogParserInterface {
        $parserClasses = self::getParserClasses($logger);

        if (isset($parserClasses[$formatName])) {
            return self::makeParser($parserClasses[$formatName], $commandRunner);
        }

        // Check for direct class match
        return match ($formatName) {
            'php_error_log' => new PhpErrorLogParser($commandRunner),
            'monolog' => new MonologParser($commandRunner),
            'json' => new JsonParser($commandRunner),
            default => null,
        };
    }

    /**
     * Clear all caches.
     *
     * Useful for testing or when log files are rotated.
     */
    public static function clearCache(): void
    {
        self::$parserClassCache = null;
        self::$detectionCache = [];
    }

    /**
     * Get list of all available parser names.
     *
     * @return array<string> Array of parser names
     */
    public static function getAvailableParsers(): array
    {
        return array_keys(self::getParserClasses());
    }
}
