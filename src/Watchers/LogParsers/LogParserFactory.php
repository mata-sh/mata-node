<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Throwable;

/**
 * Factory for auto-discovering and managing log parsers.
 *
 * Auto-discovers all *Parser.php files in the LogParsers directory.
 * Caches detection results for performance.
 */
class LogParserFactory
{
    /** @var null|array<object> Cached parser instances */
    private static ?array $parsersCache = null;

    /** @var array<string,object> Detection cache: logFile => parser instance */
    private static array $detectionCache = [];

    /**
     * Auto-discover all parser classes in LogParsers directory.
     *
     * @return array<object> Array of parser instances
     */
    private static function getAllParsers(): array
    {
        if (self::$parsersCache !== null) {
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
                    $parsers[] = new $fullClass();
                } catch (Throwable $e) {
                    error_log("Failed to load parser {$className}: " . $e->getMessage());
                }
            }
        }

        self::$parsersCache = $parsers;

        return $parsers;
    }

    /**
     * Auto-detect log format from file content.
     *
     * Tries each parser's detect() method and returns first match.
     * Results are cached per file path.
     *
     * @param string $logFile Path to log file
     *
     * @return object Parser instance (defaults to PhpErrorLogParser if no match)
     */
    public static function detectFormat(string $logFile): object
    {
        // Check cache first
        if (isset(self::$detectionCache[$logFile])) {
            return self::$detectionCache[$logFile];
        }

        $parsers = self::getAllParsers();

        // Try each parser's detect() method
        foreach ($parsers as $parser) {
            if (method_exists($parser, 'detect') && $parser->detect($logFile)) {
                self::$detectionCache[$logFile] = $parser;

                return $parser;
            }
        }

        // Fallback to PHP error_log parser (backward compatibility)
        $fallback = new PhpErrorLogParser();
        self::$detectionCache[$logFile] = $fallback;

        return $fallback;
    }

    /**
     * Get parser by format name.
     *
     * Used when log_format is specified in config.
     *
     * @param string $formatName Format name ('php_error_log', 'monolog', 'json')
     *
     * @return null|object Parser instance or null if not found
     */
    public static function getParser(string $formatName): ?object
    {
        $parsers = self::getAllParsers();

        foreach ($parsers as $parser) {
            if (method_exists($parser, 'getName') && $parser->getName() === $formatName) {
                return $parser;
            }
        }

        // Check for direct class match
        return match ($formatName) {
            'php_error_log' => new PhpErrorLogParser(),
            'monolog' => new MonologParser(),
            'json' => new JsonParser(),
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
            if (method_exists($parser, 'getName')) {
                $names[] = $parser->getName();
            }
        }

        return $names;
    }
}
