<?php

declare(strict_types=1);

namespace Mata\Config;

use Dotenv\Dotenv;
use Mata\Config\Dto\AuthConfig;
use Mata\Config\Dto\EnvConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\MailConfig;
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
 * - Structured configuration sections (app, database, mail, etc.)
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
            mail: $this->loadMailConfig(),
            rateLimiting: $this->loadRateLimitingConfig(),
            auth: $this->loadAuthConfig(),
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

    private function loadMailConfig(): MailConfig
    {
        return new MailConfig(
            dsn: $this->getEnv('SYM_MAILER_DSN'),
            fromEmail: $this->getEnv('SYM_FROM_EMAIL'),
            fromName: $this->getEnv('SYM_FROM_NAME'),
            alertRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_ALERT_RECIPIENTS', '')),
            testRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_TEST_RECIPIENTS', '')),
            devRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_DEV_RECIPIENTS', '')),
            mailerApp: $this->getEnv('MAILER_APP', 'sendgrid'),
            mailerDev: $this->getEnv('MAILER_DEV', 'phpmailer'),
            charset: $this->getEnv('MAIL_CHARSET', 'utf-8'),
            wordWrap: (int) $this->getEnv('MAIL_WORD_WRAP', '50'),
        );
    }

    private function loadRateLimitingConfig(): RateLimitingConfig
    {
        $enabled = $this->getEnvBool('RATE_LIMIT_ENABLED', true);
        $storageType = strtolower($this->getEnv('RATE_LIMIT_STORAGE', 'file'));

        // Validate storage type
        if (!in_array($storageType, ['file', 'redis', 'apcu', 'session'], true)) {
            $storageType = 'file';
        }

        return new RateLimitingConfig(
            enabled: $enabled,
            storageType: $storageType,
            filePath: $this->getEnv('RATE_LIMIT_FILE_PATH'),
            redisHost: $this->getEnv('RATE_LIMIT_REDIS_HOST', '127.0.0.1'),
            redisPort: (int) $this->getEnv('RATE_LIMIT_REDIS_PORT', '6379'),
            redisPassword: $this->getEnv('RATE_LIMIT_REDIS_PASSWORD'),
            redisDatabase: (int) $this->getEnv('RATE_LIMIT_REDIS_DATABASE', '0'),
            loginMaxRequests: (int) $this->getEnv('RATE_LIMIT_LOGIN_MAX_REQUESTS', '10'),
            loginWindowSeconds: (int) $this->getEnv('RATE_LIMIT_LOGIN_WINDOW_SECONDS', '60'),
            dashboardMaxRequests: (int) $this->getEnv('RATE_LIMIT_DASHBOARD_MAX_REQUESTS', '100'),
            dashboardWindowSeconds: (int) $this->getEnv('RATE_LIMIT_DASHBOARD_WINDOW_SECONDS', '60'),
            apiMaxRequests: (int) $this->getEnv('RATE_LIMIT_API_MAX_REQUESTS', '200'),
            apiWindowSeconds: (int) $this->getEnv('RATE_LIMIT_API_WINDOW_SECONDS', '60'),
            authFailedMaxRequests: (int) $this->getEnv('RATE_LIMIT_AUTH_FAILED_MAX_REQUESTS', '10'),
            authFailedWindowSeconds: (int) $this->getEnv('RATE_LIMIT_AUTH_FAILED_WINDOW_SECONDS', '300'),
        );
    }

    private function loadAuthConfig(): AuthConfig
    {
        return new AuthConfig(
            dashboardServerId: $this->getEnv('DASHBOARD_SERVER_ID', 'dashboard'),
            dashboardApiKey: $this->getEnv('DASHBOARD_API_KEY', ''),
            clockSkewWindow: (int) $this->getEnv('DASHBOARD_CLOCK_SKEW_WINDOW', '120'),
        );
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
     * Parse comma-separated values into array.
     *
     * @return array<int,string>
     */
    private function parseCommaSeparated(string $value): array
    {
        return array_filter(array_map('trim', explode(',', $value)));
    }

    /**
     * Get environment variable with optional default.
     */
    private function getEnv(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Validate loaded configuration and return validation results.
     *
     * @param EnvConfig $config Loaded configuration DTO
     *
     * @return array<string,mixed> Validation results with critical_errors, warnings, and is_valid
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

        // Mail configuration warnings
        if (empty($config->mail->dsn)) {
            $warnings[] = [
                'identifier' => 'config.mail.dsn.missing',
                'message' => 'Mail DSN not configured - email notifications will not work',
            ];
        }
        if (empty($config->mail->fromEmail)) {
            $warnings[] = [
                'identifier' => 'config.mail.from_email.missing',
                'message' => 'Mail from_email not configured - emails may not send properly',
            ];
        }

        return [
            'critical_errors' => $criticalErrors,
            'warnings' => $warnings,
            'is_valid' => empty($criticalErrors),
        ];
    }
}
