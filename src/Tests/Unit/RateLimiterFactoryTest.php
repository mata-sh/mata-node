<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\RateLimitingConfig;
use Mata\Factory\RateLimiterFactory;
use MataSh\RateLimiter\Storage\FileStorage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Factory\RateLimiterFactory
 *
 * @internal
 */
class RateLimiterFactoryTest extends TestCase
{
    public function testDisabledRateLimitingDoesNotInitializeStorageOrLog(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $rateLimiter = RateLimiterFactory::create($this->createConfig(enabled: false), $logger);

        $this->assertNull($rateLimiter);
        $this->assertEmpty($handler->getRecords());
    }

    public function testEnabledFileRateLimitingInitializesFileStorage(): void
    {
        $directory = sys_get_temp_dir() . '/mata-rate-limiter-factory-' . bin2hex(random_bytes(8));
        $logger = new Logger('test');

        try {
            $rateLimiter = RateLimiterFactory::create(
                $this->createConfig(enabled: true, filePath: $directory),
                $logger
            );

            $this->assertNotNull($rateLimiter);
            $this->assertInstanceOf(FileStorage::class, $rateLimiter->getRateLimiter()->getStorage());
            $this->assertDirectoryExists($directory);
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testInitializationFailureReturnsFailClosedLimiter(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $rateLimiter = RateLimiterFactory::create(
            $this->createConfig(enabled: true, storageType: 'unsupported'),
            $logger,
        );

        $this->assertNotNull($rateLimiter);
        $result = $rateLimiter->consume('api', '192.0.2.1');
        $this->assertFalse($result->isAllowed());
        $this->assertNotNull($result->getRetryAt());
        $this->assertTrue($handler->hasCriticalRecords());
    }

    private function createConfig(
        bool $enabled,
        ?string $filePath = null,
        string $storageType = 'file',
    ): RateLimitingConfig {
        return new RateLimitingConfig(
            enabled: $enabled,
            storageType: $storageType,
            filePath: $filePath,
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
        );
    }
}
