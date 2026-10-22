<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\AuthConfig;
use Mata\Config\Dto\ComposerConfig;
use Mata\Config\Dto\EnvConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
use Mata\Config\EnvConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\EnvConfigLoader
 *
 * @internal
 */
class EnvConfigLoaderTest extends TestCase
{
    public function testLoadsOptionalComposerCommandDefaults(): void
    {
        $config = $this->loadConfigEnv("COMPOSER_BIN=tools/composer.phar\nCOMPOSER_PHP_BIN=/usr/bin/php8.3-cli\n");

        $this->assertSame('tools/composer.phar', $config->composer->bin);
        $this->assertSame('/usr/bin/php8.3-cli', $config->composer->phpBin);

        $config = $this->loadConfigEnv("COMPOSER_BIN=\nCOMPOSER_PHP_BIN=\n");

        $this->assertNull($config->composer->bin);
        $this->assertNull($config->composer->phpBin);
    }

    public function testSessionRateLimitStorageFallsBackToFile(): void
    {
        $config = $this->loadConfigEnv("RATE_LIMIT_STORAGE=session\n");

        $this->assertSame('file', $config->rateLimiting->storageType);
    }

    public function testZeroIsAValueRatherThanUnset(): void
    {
        $config = $this->loadConfigEnv("RATE_LIMIT_ENABLED=0\n");

        $this->assertFalse($config->rateLimiting->enabled);
    }

    public function testEmptyDashboardApiKeyIsCriticalConfigError(): void
    {
        $result = (new EnvConfigLoader())->validateConfig($this->createConfig(dashboardApiKey: ''));

        $this->assertFalse($result['is_valid']);
        $this->assertContains(
            'config.auth.dashboard_api_key.missing',
            array_column($result['critical_errors'], 'identifier')
        );
    }

    public function testWhitespaceDashboardApiKeyIsCriticalConfigError(): void
    {
        $result = (new EnvConfigLoader())->validateConfig($this->createConfig(dashboardApiKey: '   '));

        $this->assertFalse($result['is_valid']);
        $this->assertContains(
            'config.auth.dashboard_api_key.missing',
            array_column($result['critical_errors'], 'identifier')
        );
    }

    public function testConfiguredDashboardApiKeyPassesCriticalValidation(): void
    {
        $result = (new EnvConfigLoader())->validateConfig($this->createConfig(dashboardApiKey: 'test-secret'));

        $this->assertTrue($result['is_valid']);
        $this->assertSame([], $result['critical_errors']);
    }

    public function testEnabledIpWhitelistWithEmptyAllowedIpsIsCriticalConfigError(): void
    {
        $result = (new EnvConfigLoader())->validateConfig($this->createConfig(
            dashboardApiKey: 'test-secret',
            ipWhitelistEnabled: true,
            allowedIps: [],
        ));

        $this->assertFalse($result['is_valid']);
        $this->assertContains(
            'config.ip_whitelist.allowed_ips.missing',
            array_column($result['critical_errors'], 'identifier')
        );
    }

    public function testInvalidAllowedIpIsCriticalConfigError(): void
    {
        $result = (new EnvConfigLoader())->validateConfig($this->createConfig(
            dashboardApiKey: 'test-secret',
            allowedIps: ['not-an-ip'],
        ));

        $this->assertFalse($result['is_valid']);
        $this->assertContains(
            'config.ip_whitelist.allowed_ips.invalid',
            array_column($result['critical_errors'], 'identifier')
        );
    }

    public function testInvalidTrustedProxyIsCriticalConfigError(): void
    {
        $result = (new EnvConfigLoader())->validateConfig($this->createConfig(
            dashboardApiKey: 'test-secret',
            trustedProxies: ['10.0.0.0/999'],
        ));

        $this->assertFalse($result['is_valid']);
        $this->assertContains(
            'config.ip_whitelist.trusted_proxies.invalid',
            array_column($result['critical_errors'], 'identifier')
        );
    }

    /**
     * Load a config.env with these contents; its variables are unset before and restored after.
     */
    private function loadConfigEnv(string $contents): EnvConfig
    {
        $configDir = sys_get_temp_dir() . '/mata-node-env-' . bin2hex(random_bytes(6));
        mkdir($configDir, 0777, true);
        file_put_contents($configDir . '/config.env', $contents);

        preg_match_all('/^([A-Z0-9_]+)=/m', $contents, $matches);
        $previous = [];
        foreach ($matches[1] as $key) {
            $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            return (new EnvConfigLoader())->load(['config.env'], $configDir);
        } finally {
            foreach ($previous as $key => [$env, $server, $process]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                putenv($process === false ? $key : "{$key}={$process}");
            }

            unlink($configDir . '/config.env');
            rmdir($configDir);
        }
    }

    /**
     * @param array<int,string> $allowedIps
     * @param array<int,string> $trustedProxies
     */
    private function createConfig(
        string $dashboardApiKey,
        bool $ipWhitelistEnabled = false,
        array $allowedIps = [],
        array $trustedProxies = [],
    ): EnvConfig {

        return new EnvConfig(
            environment: new EnvironmentConfig(
                env: 'test',
                domain: 'example.test',
                dir: '',
                tempDir: '',
            ),
            general: new GeneralConfig(
                maintenanceModeEnabled: false,
                ipWhitelistEnabled: $ipWhitelistEnabled,
                allowedIps: $allowedIps,
                trustedProxies: $trustedProxies,
            ),
            php: new PhpConfig(
                displayErrors: 0,
                logLevel: 'notices',
            ),
            logging: new LoggingConfig(
                path: 'logs/app.log',
                level: 'debug',
            ),
            rateLimiting: new RateLimitingConfig(
                enabled: true,
                storageType: 'file',
                filePath: null,
                redisHost: '127.0.0.1',
                redisPort: 6379,
                redisPassword: null,
                redisDatabase: 0,
                loginMaxRequests: 10,
                loginWindowSeconds: 60,
                dashboardMaxRequests: 100,
                dashboardWindowSeconds: 60,
                apiMaxRequests: 200,
                apiWindowSeconds: 60,
                authFailedMaxRequests: 10,
                authFailedWindowSeconds: 300,
            ),
            auth: new AuthConfig(
                dashboardServerId: 'dashboard',
                dashboardApiKey: $dashboardApiKey,
                clockSkewWindow: 120,
            ),
            composer: new ComposerConfig(
                bin: null,
                phpBin: null,
            ),
        );
    }
}
