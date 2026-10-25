<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Mata\Utils\Tools;
use Mata\Watchers\App\AppMonitoringSettings;
use Mata\Watchers\LogParsers\LogParserFactory;

/**
 * Checks one `apps.json` entry the way the node reads it. AppWatcher fails an app on the first
 * invalid metadata field; `config validate` reports every problem, including settings the node
 * silently skips or replaces with a fallback.
 */
final class AppConfigValidator
{
    private const FILE = 'apps.json';
    private const ENVIRONMENTS = ['dev', 'test', 'stg', 'prod'];
    private const SESSION_TYPES = ['file', 'redis', 'none'];
    private const DISABLED_VALUES = [true, false, 'true', 'false'];

    public function __construct(private readonly Tools $tools = new Tools()) {}

    /**
     * `disabled` takes a JSON boolean or the string form older configs use.
     *
     * @param array<string, mixed> $appConfig
     */
    public static function isDisabled(array $appConfig): bool
    {
        $disabled = $appConfig['disabled'] ?? false;

        return $disabled === true || $disabled === 'true';
    }

    /**
     * The metadata AppWatcher reads, parsed once. `invalid_fields` lists the invalid fields in
     * the order AppWatcher checks them; any of them makes `/apps` and the app's routes fail with
     * the generic configuration error, and `status_url` and `monitoring` are then meaningless.
     *
     * @param array<string, mixed> $appConfig
     *
     * @return array{invalid_fields: list<string>, status_url: ?string, monitoring: ?AppMonitoringSettings}
     */
    public static function parseMetadata(array $appConfig): array
    {
        $invalid = [];
        foreach (['title', 'url', 'path'] as $field) {
            $value = $appConfig[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $invalid[] = $field;
            }
        }

        if (!in_array($appConfig['env'] ?? null, self::ENVIRONMENTS, true)) {
            $invalid[] = 'env';
        }

        $statusUrl = null;
        if (array_key_exists('status_endpoint', $appConfig) && $appConfig['status_endpoint'] !== '') {
            $endpoint = $appConfig['status_endpoint'];
            if (is_string($endpoint) && self::isHttpUrl($endpoint)) {
                $statusUrl = $endpoint;
            } else {
                $invalid[] = 'status_endpoint';
            }
        }

        $monitoring = AppMonitoringSettings::parse($appConfig['monitoring'] ?? null);
        if ($monitoring['invalid_field'] !== null) {
            $invalid[] = $monitoring['invalid_field'];
        }

        return ['invalid_fields' => $invalid, 'status_url' => $statusUrl, 'monitoring' => $monitoring['settings']];
    }

    /**
     * An absolute http(s) URL without surrounding whitespace or a fragment.
     */
    private static function isHttpUrl(string $url): bool
    {
        if (trim($url) !== $url || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && !isset($parts['fragment']);
    }

    /**
     * Every problem in one app entry. Disabled apps are not checked, as the node never reads them.
     *
     * @param string $rootPath Directory relative app paths resolve against
     *
     * @return list<ConfigProblem>
     */
    public function validate(string $app, mixed $appConfig, string $rootPath): array
    {
        if (!is_array($appConfig)) {
            return [ConfigProblem::error(self::FILE, 'The app entry must be a JSON object', $app)];
        }

        $problems = [];
        if (!in_array($appConfig['disabled'] ?? false, self::DISABLED_VALUES, true)) {
            $problems[] = ConfigProblem::warning(self::FILE, 'disabled must be true or false; any other value keeps the app enabled', $app, 'disabled');
        }

        if (self::isDisabled($appConfig)) {
            return $problems;
        }

        if ($app !== strtolower($app)) {
            $problems[] = ConfigProblem::error(self::FILE, 'The app id must be lowercase: the node looks apps up by their lowercased id, so /apps fails with "Unknown app"', $app);
        }

        foreach (self::parseMetadata($appConfig)['invalid_fields'] as $field) {
            $problems[] = ConfigProblem::error(self::FILE, self::metadataMessage($field), $app, $field);
        }

        return [
            ...$problems,
            ...$this->pathProblems($app, $appConfig['path'] ?? null, $rootPath),
            ...$this->logProblems($app, $appConfig['logs'] ?? null),
            ...$this->sessionProblems($app, $appConfig['sessions'] ?? null),
        ];
    }

    private static function metadataMessage(string $field): string
    {
        if (str_starts_with($field, 'monitoring.')) {
            $setting = substr($field, strlen('monitoring.'));

            return in_array($setting, AppMonitoringSettings::SETTINGS, true)
                ? "{$field} must be true or false (a JSON boolean)"
                : "{$setting} is not a monitoring setting; known: " . implode(', ', AppMonitoringSettings::SETTINGS);
        }

        return match ($field) {
            'env' => 'env must be one of ' . implode(', ', self::ENVIRONMENTS),
            'status_endpoint' => 'status_endpoint must be empty or an absolute http(s) URL without a fragment',
            'monitoring' => 'monitoring must be a JSON object of monitoring switches',
            default => "{$field} is required and must be a non-empty string",
        };
    }

    /**
     * A missing or non-string path is already reported as invalid metadata.
     *
     * @return list<ConfigProblem>
     */
    private function pathProblems(string $app, mixed $path, string $rootPath): array
    {
        if (!is_string($path) || trim($path) === '') {
            return [];
        }

        $directory = $this->tools->normalizePath($path, $rootPath);
        if (is_dir($directory) && is_readable($directory)) {
            return [];
        }

        return [ConfigProblem::warning(self::FILE, "{$directory} is not a directory this user can read", $app, 'path')];
    }

    /**
     * Malformed log entries are skipped by the node and an unknown type is auto-detected, so
     * both only warn. A non-string type breaks the app's log statistics.
     *
     * @return list<ConfigProblem>
     */
    private function logProblems(string $app, mixed $logs): array
    {
        if ($logs === null) {
            return [];
        }

        if (!is_array($logs)) {
            return [ConfigProblem::warning(self::FILE, 'logs must be a list of paths or {"type", "paths"} groups; ignored', $app, 'logs')];
        }

        $problems = [];
        $knownTypes = LogParserFactory::getAvailableParsers();
        foreach ($logs as $index => $group) {
            $field = "logs[{$index}]";
            if (is_string($group)) {
                if ($group === '') {
                    $problems[] = ConfigProblem::warning(self::FILE, 'Empty log path; ignored', $app, $field);
                }

                continue;
            }

            if (!is_array($group)) {
                $problems[] = ConfigProblem::warning(self::FILE, 'A log entry must be a path or a {"type", "paths"} group; ignored', $app, $field);

                continue;
            }

            $type = $group['type'] ?? '';
            if (!is_string($type)) {
                $problems[] = ConfigProblem::error(self::FILE, 'type must be a string; the app\'s log statistics fail otherwise', $app, "{$field}.type");
            } elseif ($type !== '' && !in_array($type, $knownTypes, true)) {
                $problems[] = ConfigProblem::warning(
                    self::FILE,
                    "Unknown log type \"{$type}\"; the node auto-detects the format instead. Known: " . implode(', ', $knownTypes),
                    $app,
                    "{$field}.type",
                );
            }

            $paths = $group['paths'] ?? [];
            if (!is_array($paths) || $paths === []) {
                $problems[] = ConfigProblem::warning(self::FILE, 'paths must be a non-empty list of log paths; group ignored', $app, "{$field}.paths");

                continue;
            }

            foreach ($paths as $pathIndex => $path) {
                if (!is_string($path) || $path === '') {
                    $problems[] = ConfigProblem::warning(self::FILE, 'A log path must be a non-empty string; ignored', $app, "{$field}.paths[{$pathIndex}]");
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<ConfigProblem>
     */
    private function sessionProblems(string $app, mixed $sessions): array
    {
        if ($sessions === null) {
            return [];
        }

        if (!is_array($sessions)) {
            return [ConfigProblem::warning(self::FILE, 'sessions must be a JSON object; ignored', $app, 'sessions')];
        }

        $type = $sessions['type'] ?? null;
        if (is_string($type) && trim($type) !== '' && !in_array(strtolower(trim($type)), self::SESSION_TYPES, true)) {
            return [ConfigProblem::warning(
                self::FILE,
                "Unknown session type \"{$type}\"; the node reports the sessions as unsupported. Known: " . implode(', ', self::SESSION_TYPES),
                $app,
                'sessions.type',
            )];
        }

        return [];
    }
}
