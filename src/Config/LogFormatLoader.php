<?php

declare(strict_types=1);

namespace Mata\Config;

use Mata\Config\Dto\LogFormatConfig;
use Mata\Config\Validation\ConfigProblem;
use Mata\Watchers\LogParsers\DefinedLogParser;
use Mata\Watchers\LogParsers\LogParserFactory;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads log formats defined in YAML, one `<name>.yaml` or `<name>.yml` per format: those shipped
 * with the node from resources/log-formats/, then the instance's own from config/log-formats/. The
 * directory is optional. A broken file or a name that is taken skips only that format, with a
 * problem for `config validate`; apps that name it get an auto-detected parser, as for any unknown
 * type.
 */
final class LogFormatLoader
{
    /** Directory of the instance's formats, in the config directory. */
    public const CONFIG_DIRECTORY = 'log-formats';

    private const BUILT_IN_DIRECTORY = __DIR__ . '/../../resources/log-formats';
    private const KEYS = ['description', 'pattern', 'json', 'levels'];
    private const JSON_FIELDS = ['timestamp', 'level', 'message'];
    private const LEVEL_TYPES = ['error' => 'Error', 'warning' => 'Warning'];
    private const NAME = '/^[a-z][a-z0-9_]*$/';
    private const FILE = '/^(.*)\.ya?ml$/';

    public function __construct(private readonly string $builtInDirectory = self::BUILT_IN_DIRECTORY) {}

    /**
     * @return array{
     *     formats: array<string, LogFormatConfig>,
     *     files: list<string>,
     *     problems: list<ConfigProblem>
     * } `files` are the instance's format files, relative to the config directory
     */
    public function load(string $configDir): array
    {
        $formats = [];
        $problems = [];
        $taken = array_fill_keys(array_keys(LogParserFactory::BUILT_IN), true);

        $builtIn = $this->readDirectory($this->builtInDirectory, 'resources/log-formats', $taken, $problems);
        $taken += array_fill_keys(array_keys($builtIn['formats']), true);
        $own = $this->readDirectory($configDir . '/' . self::CONFIG_DIRECTORY, self::CONFIG_DIRECTORY, $taken, $problems);

        return ['formats' => $builtIn['formats'] + $own['formats'], 'files' => $own['files'], 'problems' => $problems];
    }

    /**
     * @param array<string, true> $taken    names already in use
     * @param list<ConfigProblem> $problems
     *
     * @return array{formats: array<string, LogFormatConfig>, files: list<string>} files as `<label>/<file>`
     */
    private function readDirectory(string $directory, string $label, array $taken, array &$problems): array
    {
        $none = ['formats' => [], 'files' => []];
        if (!is_dir($directory)) {
            return $none;
        }

        $entries = @scandir($directory);
        if ($entries === false) {
            $problems[] = ConfigProblem::warning($label, "{$directory} is not a directory this user can read; its formats are not loaded");

            return $none;
        }

        $formats = [];
        $files = [];
        $defined = [];
        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $file = "{$label}/{$entry}";
            if (preg_match(self::FILE, $entry, $match) !== 1) {
                $problems[] = ConfigProblem::warning($file, 'Ignored: only <name>.yaml or <name>.yml files define formats');

                continue;
            }

            $files[] = $file;
            $name = $match[1];
            $problem = match (true) {
                preg_match(self::NAME, $name) !== 1 => 'The file name is the format name and must use lowercase letters, digits and underscores, e.g. nginx_error.yaml',
                isset($taken[$name]) => "\"{$name}\" is already a built-in format; rename the file",
                isset($defined[$name]) => "{$defined[$name]} already defines \"{$name}\"; remove one of the two files",
                default => null,
            };
            $defined[$name] ??= $entry;

            $format = $problem === null ? $this->readFormat($name, "{$directory}/{$entry}", $problem) : null;
            if ($format === null) {
                $problems[] = ConfigProblem::warning($file, "{$problem}; format not loaded");

                continue;
            }

            $formats[$name] = $format;
        }

        return ['formats' => $formats, 'files' => $files];
    }

    /**
     * @param null|string $problem set to why the file defines no format
     */
    private function readFormat(string $name, string $path, ?string &$problem): ?LogFormatConfig
    {
        try {
            $definition = Yaml::parseFile($path);
        } catch (ParseException $e) {
            $problem = "Invalid YAML: {$e->getMessage()}";

            return null;
        }

        $problem = $this->definitionProblem($definition);
        if ($problem !== null) {
            return null;
        }

        $levels = [];
        foreach ($definition['levels'] as $levelType => $values) {
            foreach ($values as $value) {
                $levels[strtolower(trim((string) $value))] = self::LEVEL_TYPES[$levelType];
            }
        }

        $json = $definition['json'] ?? null;

        return new LogFormatConfig(
            name: $name,
            pattern: isset($definition['pattern']) ? DefinedLogParser::compile($definition['pattern']) : null,
            jsonFields: $json === null ? null : [
                'timestamp' => $json['timestamp'] ?? null,
                'level' => $json['level'],
                'message' => $json['message'],
            ],
            levels: $levels,
        );
    }

    /** What is wrong with a parsed format file, or null when it defines a format. */
    private function definitionProblem(mixed $definition): ?string
    {
        if (!is_array($definition) || array_is_list($definition)) {
            return 'Must be a mapping with pattern or json, and levels';
        }

        $unknown = array_diff(array_keys($definition), self::KEYS);
        if ($unknown !== []) {
            return 'Unknown key ' . implode(', ', $unknown) . '; expected ' . implode(', ', self::KEYS);
        }

        if (isset($definition['description']) && !is_string($definition['description'])) {
            return 'description must be text';
        }

        $hasPattern = array_key_exists('pattern', $definition);
        $hasJson = array_key_exists('json', $definition);
        if ($hasPattern === $hasJson) {
            return 'Set either pattern, for text lines, or json, for one JSON record per line';
        }

        return ($hasPattern ? $this->patternProblem($definition['pattern']) : $this->jsonProblem($definition['json']))
            ?? $this->levelsProblem($definition['levels'] ?? null);
    }

    private function patternProblem(mixed $pattern): ?string
    {
        if (!is_string($pattern) || $pattern === '') {
            return 'pattern must be a regular expression';
        }

        if (@preg_match(DefinedLogParser::compile($pattern), '') === false) {
            $error = error_get_last()['message'] ?? preg_last_error_msg();

            return 'pattern is not a valid regular expression: ' . preg_replace('/^preg_match\(\): /', '', $error);
        }

        foreach (['level', 'message'] as $group) {
            if (preg_match("/\\(\\?P?<{$group}>|\\(\\?'{$group}'/", $pattern) !== 1) {
                return "pattern needs a named group (?<{$group}>…)";
            }
        }

        return null;
    }

    private function jsonProblem(mixed $json): ?string
    {
        if (!is_array($json) || array_is_list($json)) {
            return 'json must map timestamp, level and message to fields of the record';
        }

        $unknown = array_diff(array_keys($json), self::JSON_FIELDS);
        if ($unknown !== []) {
            return 'Unknown json key ' . implode(', ', $unknown) . '; expected ' . implode(', ', self::JSON_FIELDS);
        }

        foreach (self::JSON_FIELDS as $key) {
            $field = $json[$key] ?? null;
            $required = $key !== 'timestamp';
            if (($field !== null || $required) && (!is_string($field) || $field === '')) {
                return "json.{$key} must name a field of the record";
            }
        }

        return null;
    }

    private function levelsProblem(mixed $levels): ?string
    {
        $expected = 'levels must list the levels that are an error and those that are a warning, e.g. error: [ERROR, CRITICAL]';
        if (!is_array($levels) || $levels === [] || array_is_list($levels)) {
            return $expected;
        }

        $seen = [];
        foreach ($levels as $levelType => $values) {
            if (!isset(self::LEVEL_TYPES[$levelType])) {
                return "Unknown level type {$levelType}; expected error or warning";
            }

            if (!is_array($values) || $values === [] || !array_is_list($values)) {
                return "levels.{$levelType} must be a list of levels";
            }

            foreach ($values as $value) {
                $level = is_scalar($value) ? strtolower(trim((string) $value)) : '';
                if ($level === '') {
                    return "levels.{$levelType} must list levels as text";
                }
                if (isset($seen[$level]) && $seen[$level] !== $levelType) {
                    return "Level {$value} is listed as both error and warning";
                }
                $seen[$level] = $levelType;
            }
        }

        return null;
    }
}
