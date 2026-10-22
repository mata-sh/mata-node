<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\RateLimitingConfig;
use Mata\Services\RateLimiter;
use MataSh\RateLimiter\RateLimiter as MataShRateLimiter;
use MataSh\RateLimiter\RateLimitIdentifier;
use MataSh\RateLimiter\RateLimitResult;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Mata\Services\RateLimiter
 *
 * @internal
 */
class RateLimiterTest extends TestCase
{
    public function testConsumeReturnsAllowedPackageResultWithGroupIdentifier(): void
    {
        $packageResult = new RateLimitResult(true, 1, null);
        $packageRateLimiter = $this->createMock(MataShRateLimiter::class);
        $packageRateLimiter->expects($this->once())
            ->method('consume')
            ->with('api_' . RateLimitIdentifier::fromIp('192.0.2.1'), 200, 60)
            ->willReturn($packageResult);
        $rateLimiter = new RateLimiter($packageRateLimiter, $this->createConfig(), new Logger('test'));

        $this->assertSame($packageResult, $rateLimiter->consume('api', '192.0.2.1'));
    }

    public function testRouteGroupsUseIndependentIdentifiers(): void
    {
        $identifiers = [];
        $packageRateLimiter = $this->createStub(MataShRateLimiter::class);
        $packageRateLimiter->method('consume')->willReturnCallback(
            static function (string $identifier) use (&$identifiers): RateLimitResult {
                $identifiers[] = $identifier;

                return new RateLimitResult(true, 1, null);
            },
        );
        $rateLimiter = new RateLimiter($packageRateLimiter, $this->createConfig(), new Logger('test'));

        $rateLimiter->consume('api', '192.0.2.1');
        $rateLimiter->consume('auth_failed', '192.0.2.1');

        $ipIdentifier = RateLimitIdentifier::fromIp('192.0.2.1');
        $this->assertSame(['api_' . $ipIdentifier, 'auth_failed_' . $ipIdentifier], $identifiers);
    }

    public function testRuntimeFailureIsLoggedAndDeniedWithBoundedRetry(): void
    {
        $handler = new TestHandler();
        $packageRateLimiter = $this->createStub(MataShRateLimiter::class);
        $packageRateLimiter->method('consume')->willThrowException(new RuntimeException('backend failed'));
        $rateLimiter = new RateLimiter($packageRateLimiter, $this->createConfig(), new Logger('test', [$handler]));
        $before = time();

        $result = $rateLimiter->consume('api', '192.0.2.1');

        $this->assertFalse($result->isAllowed());
        $this->assertGreaterThanOrEqual($before + 60, $result->getRetryAt());
        $this->assertLessThanOrEqual(time() + 60, $result->getRetryAt());
        $this->assertTrue($handler->hasErrorRecords());
    }

    public function testIsExhaustedChecksWithoutConsuming(): void
    {
        $packageRateLimiter = $this->createMock(MataShRateLimiter::class);
        $packageRateLimiter->expects($this->never())->method('consume');
        $packageRateLimiter->expects($this->once())
            ->method('check')
            ->with('auth_failed_' . RateLimitIdentifier::fromIp('192.0.2.1'), 10, 300)
            ->willReturn(['allowed' => false, 'current' => 10, 'limit' => 10, 'remaining' => 0, 'storage_type' => 'file']);
        $rateLimiter = new RateLimiter($packageRateLimiter, $this->createConfig(), new Logger('test'));

        $this->assertTrue($rateLimiter->isExhausted('auth_failed', '192.0.2.1'));
    }

    public function testIsExhaustedFailsClosed(): void
    {
        $handler = new TestHandler();
        $packageRateLimiter = $this->createStub(MataShRateLimiter::class);
        $packageRateLimiter->method('check')->willThrowException(new RuntimeException('backend failed'));
        $rateLimiter = new RateLimiter($packageRateLimiter, $this->createConfig(), new Logger('test', [$handler]));

        $this->assertTrue($rateLimiter->isExhausted('auth_failed', '192.0.2.1'));
        $this->assertTrue($handler->hasErrorRecords());
    }

    public function testDisabledRateLimitingRemainsAllowed(): void
    {
        $packageRateLimiter = $this->createMock(MataShRateLimiter::class);
        $packageRateLimiter->expects($this->never())->method('consume');
        $rateLimiter = new RateLimiter($packageRateLimiter, $this->createConfig(enabled: false), new Logger('test'));

        $this->assertTrue($rateLimiter->consume('api', '192.0.2.1')->isAllowed());
    }

    private function createConfig(bool $enabled = true): RateLimitingConfig
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
