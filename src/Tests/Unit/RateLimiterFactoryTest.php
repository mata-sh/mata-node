<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\RateLimitingConfig;
use Mata\Factory\RateLimiterFactory;
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

    private function createConfig(bool $enabled): RateLimitingConfig
    {
        return new RateLimitingConfig(
            enabled: $enabled,
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
        );
    }
}
