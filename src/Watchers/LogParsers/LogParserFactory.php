<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Mata\Services\ShellRunnerInterface;
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
    /** @var null|array<int,LogParserInterface> Cached parser instances */
    private static ?array $parsersCache = null;

    /** @var array<string,LogParserInterface> Detection cache: logFile => parser instance */
    private static array $detectionCache = [];

    /**
     * Auto-discover all parser classes in LogParsers directory.
     *
     * @param null|LoggerInterface $logger Logger for reporting parsers that fail to load
     *
     * @return array<int,LogParserInterface> Array of parser instances
     */
    private static function getAllParsers(?LoggerInterface $logger = null, ?ShellRunnerInterface $shellRunner = null): array
    {
        if ($shellRunner === null && self::$parsersCache !== null) {
            return self::$parsersCache;
        }

        $parsers = [];
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

            if (class_exists($fullClass)) {
                try {
                    if (!(new ReflectionClass($fullClass))->isInstantiable()) {
                        continue;
                    }

                    $parser = $shellRunner === null ? new $fullClass() : new $fullClass($shellRunner);
                    if ($parser instanceof LogParserInterface) {
                        $parsers[] = $parser;
                    }
                } catch (Throwable $e) {
                    $logger?->warning('Failed to load parser', ['class' => $className, 'error' => $e->getMessage()]);
                }
            }
        }

        if ($shellRunner === null) {
            self::$parsersCache = $parsers;
        }

        return $parsers;
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
        ?ShellRunnerInterface $shellRunner = null
    ): LogParserInterface {
        // Check cache first
        if ($shellRunner === null && isset(self::$detectionCache[$logFile])) {
            return self::$detectionCache[$logFile];
        }

        $parsers = self::getAllParsers($logger, $shellRunner);

        // Try each parser's detect() method
        foreach ($parsers as $parser) {
            if ($parser->detect($logFile)) {
                if ($shellRunner === null) {
                    self::$detectionCache[$logFile] = $parser;
                }

                return $parser;
            }
        }

        // Fallback to PHP error_log parser (backward compatibility)
        $fallback = new PhpErrorLogParser($shellRunner);
        if ($shellRunner === null) {
            self::$detectionCache[$logFile] = $fallback;
        }

        return $fallback;
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
        ?ShellRunnerInterface $shellRunner = null
    ): ?LogParserInterface {
        $parsers = self::getAllParsers($logger, $shellRunner);

        foreach ($parsers as $parser) {
            if ($parser->getName() === $formatName) {
                return $parser;
            }
        }

        // Check for direct class match
        return match ($formatName) {
            'php_error_log' => new PhpErrorLogParser($shellRunner),
            'monolog' => new MonologParser($shellRunner),
            'json' => new JsonParser($shellRunner),
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
        self::$parsersCache = null;
        self::$detectionCache = [];
    }

    /**
     * Get list of all available parser names.
     *
     * @return array<string> Array of parser names
     */
    public static function getAvailableParsers(): array
    {
        $parsers = self::getAllParsers();
        $names = [];

        foreach ($parsers as $parser) {
            $names[] = $parser->getName();
        }

        return $names;
    }
}
