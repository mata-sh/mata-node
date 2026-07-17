<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\AuthConfig;
use Mata\Config\Dto\EnvConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\MailConfig;
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
            mail: new MailConfig(
                dsn: null,
                fromEmail: null,
                fromName: null,
                alertRecipients: [],
                testRecipients: [],
                devRecipients: [],
                mailerApp: 'sendgrid',
                mailerDev: 'phpmailer',
                charset: 'utf-8',
                wordWrap: 50,
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
        );
    }
}
