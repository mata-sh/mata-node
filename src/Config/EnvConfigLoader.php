<?php

declare(strict_types=1);

namespace Mata\Config;

use Dotenv\Dotenv;
use InvalidArgumentException;
use Mata\Config\Dto\AuthConfig;
use Mata\Config\Dto\ComposerConfig;
use Mata\Config\Dto\EnvConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
use Mata\Security\IpAllowlist;

/**
 * Configuration file loader supporting multiple .env formats.
 *
 * Handles comprehensive configuration loading with:
 * - Multiple .env file support
 * - Environment variable validation
 * - Type-safe configuration parsing via DTOs
 * - Structured configuration sections (app, logging, auth, etc.)
 * - Default value handling
 * - Comma-separated value parsing
 * - Boolean value normalization
 */
final class EnvConfigLoader
{
    /**
     * Load and parse configuration from multiple .env files.
     *
     * @param array<int,string> $envFiles   List of .env file names to load
     * @param string            $configPath Directory path containing configuration files
     *
     * @return EnvConfig Type-safe application configuration DTO
     */
    public function load(array $envFiles, string $configPath): EnvConfig
    {
        foreach ($envFiles as $file) {
            $fullPath = $configPath . DIRECTORY_SEPARATOR . $file;
            if (file_exists($fullPath)) {
                Dotenv::createImmutable($configPath, $file)->load();
            }
        }

        return new EnvConfig(
            environment: $this->loadEnvironmentConfig(),
            general: $this->loadGeneralConfig(),
            php: $this->loadPhpConfig(),
            logging: $this->loadLoggingConfig(),
            rateLimiting: $this->loadRateLimitingConfig(),
            auth: $this->loadAuthConfig(),
            composer: $this->loadComposerConfig(),
        );
    }

    private function loadEnvironmentConfig(): EnvironmentConfig
    {
        return new EnvironmentConfig(
            env: $this->getEnv('APP_ENV', 'prod'),
            domain: $this->getEnv('DOMAIN', ''),
            dir: $this->getEnv('DIR', ''),
            tempDir: $this->getEnv('TEMP_DIR', ''),
        );
    }

    private function loadGeneralConfig(): GeneralConfig
    {
        return new GeneralConfig(
            maintenanceModeEnabled: $this->getEnvBool('MAINTENANCE_MODE_ENABLED', false),
            ipWhitelistEnabled: $this->getEnvBool('IP_WHITELIST_ENABLED', false),
            allowedIps: $this->parseCommaSeparated($this->getEnv('ALLOWED_IPS', '')),
            trustedProxies: $this->parseCommaSeparated($this->getEnv('TRUSTED_PROXIES', '')),
        );
    }

    private function loadPhpConfig(): PhpConfig
    {
        return new PhpConfig(
            displayErrors: (int) $this->getEnv('DISPLAY_ERRORS', '0'),
            logLevel: $this->getEnv('LOG_LEVEL', 'notices'),
        );
    }

    private function loadLoggingConfig(): LoggingConfig
    {
        return new LoggingConfig(
            path: $this->getEnv('APP_LOG_PATH', 'logs/app.log'),
            level: $this->getEnv('MONOLOG_LEVEL', 'debug'),
        );
    }

    private function loadRateLimitingConfig(): RateLimitingConfig
    {
        $enabled = $this->getEnvBool('RATE_LIMIT_ENABLED', true);
        $storageType = strtolower($this->getEnv('RATE_LIMIT_STORAGE', 'file'));

        // Validate storage type
        if (!in_array($storageType, ['file', 'redis', 'apcu'], true)) {
            $storageType = 'file';
        }

        return new RateLimitingConfig(
            enabled: $enabled,
            storageType: $storageType,
            filePath: $this->getEnv('RATE_LIMIT_FILE_PATH'),
            redisHost: $this->getEnv('RATE_LIMIT_REDIS_HOST', '127.0.0.1'),
            redisPort: $this->getEnvInt('RATE_LIMIT_REDIS_PORT', 6379, minimum: 1, maximum: 65535),
            redisPassword: $this->getEnv('RATE_LIMIT_REDIS_PASSWORD'),
            redisDatabase: $this->getEnvInt('RATE_LIMIT_REDIS_DATABASE', 0, minimum: 0),
            apiMaxRequests: $this->getEnvInt('RATE_LIMIT_API_MAX_REQUESTS', 200),
            apiWindowSeconds: $this->getEnvInt('RATE_LIMIT_API_WINDOW_SECONDS', 60),
            authFailedMaxRequests: $this->getEnvInt('RATE_LIMIT_AUTH_FAILED_MAX_REQUESTS', 10),
            authFailedWindowSeconds: $this->getEnvInt('RATE_LIMIT_AUTH_FAILED_WINDOW_SECONDS', 300),
        );
    }

    private function loadAuthConfig(): AuthConfig
    {
        return new AuthConfig(
            dashboardServerId: $this->getEnv('DASHBOARD_SERVER_ID', 'dashboard'),
            dashboardApiKey: $this->getEnv('DASHBOARD_API_KEY', ''),
            clockSkewWindow: $this->getEnvInt('DASHBOARD_CLOCK_SKEW_WINDOW', 120),
        );
    }

    private function loadComposerConfig(): ComposerConfig
    {
        return new ComposerConfig(
            bin: $this->getNonEmptyEnv('COMPOSER_BIN'),
            phpBin: $this->getNonEmptyEnv('COMPOSER_PHP_BIN'),
        );
    }

    private function getNonEmptyEnv(string $key): ?string
    {
        $value = $this->getEnv($key);

        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /**
     * Get environment variable as boolean.
     */
    private function getEnvBool(string $key, bool $default = false): bool
    {
        $value = $this->getEnv($key, $default ? 'ON' : 'OFF');

        return in_array(strtoupper($value), ['ON', 'TRUE', '1', 'YES'], true);
    }

    /**
     * Get environment variable as an integer; anything else fails loading instead of becoming 0.
     *
     * @throws InvalidArgumentException When the value is not an integer within the bounds
     */
    private function getEnvInt(string $key, int $default, int $minimum = 1, int $maximum = PHP_INT_MAX): int
    {
        $value = $this->getEnv($key);
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($parsed === false) {
            $range = $maximum === PHP_INT_MAX ? "at least {$minimum}" : "from {$minimum} to {$maximum}";

            throw new InvalidArgumentException("{$key} must be an integer {$range}, got \"{$value}\"");
        }

        return $parsed;
    }

    /**
     * Parse comma-separated values into array.
     *
     * @return array<int,string>
     */
    private function parseCommaSeparated(string $value): array
    {
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get environment variable; unset and empty values fall back to the default.
     */
    private function getEnv(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Validate loaded configuration and return validation results.
     *
     * @param EnvConfig $config Loaded configuration DTO
     *
     * @return array{
     *     critical_errors: list<array{identifier: string, message: string}>,
     *     warnings: list<array{identifier: string, message: string}>,
     *     is_valid: bool,
     * }
     */
    public function validateConfig(EnvConfig $config): array
    {
        $criticalErrors = [];
        $warnings = [];

        // Critical validation - DOMAIN
        if (empty($config->environment->domain)) {
            $criticalErrors[] = [
                'identifier' => 'config.domain.missing',
                'message' => 'DOMAIN is required but missing from configuration',
            ];
        }

        // Critical validation - dashboard HMAC secret
        if ('' === trim($config->auth->dashboardApiKey)) {
            $criticalErrors[] = [
                'identifier' => 'config.auth.dashboard_api_key.missing',
                'message' => 'DASHBOARD_API_KEY is required but missing from configuration',
            ];
        }

        // Critical validation - IP allowlist
        if ($config->general->ipWhitelistEnabled && [] === $config->general->allowedIps) {
            $criticalErrors[] = [
                'identifier' => 'config.ip_whitelist.allowed_ips.missing',
                'message' => 'IP_WHITELIST_ENABLED is ON but ALLOWED_IPS is empty',
            ];
        }

        foreach ($config->general->allowedIps as $entry) {
            if (!IpAllowlist::isValidEntry($entry)) {
                $criticalErrors[] = [
                    'identifier' => 'config.ip_whitelist.allowed_ips.invalid',
                    'message' => "Invalid ALLOWED_IPS entry: {$entry}",
                ];
            }
        }

        foreach ($config->general->trustedProxies as $entry) {
            if (!IpAllowlist::isValidEntry($entry)) {
                $criticalErrors[] = [
                    'identifier' => 'config.ip_whitelist.trusted_proxies.invalid',
                    'message' => "Invalid TRUSTED_PROXIES entry: {$entry}",
                ];
            }
        }

        // Logging configuration warnings
        if (empty($config->logging->path)) {
            $warnings[] = [
                'identifier' => 'config.logging.file.missing',
                'message' => 'Log file path not configured - logs may not be written to file',
            ];
        }

        if (empty($config->logging->level)) {
            $warnings[] = [
                'identifier' => 'config.logging.level.missing',
                'message' => 'Monolog level not configured - default log level may be used',
            ];
        }

        return [
            'critical_errors' => $criticalErrors,
            'warnings' => $warnings,
            'is_valid' => empty($criticalErrors),
        ];
    }
}
