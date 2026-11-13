<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Http\ClientIpResolver;
use Mata\Security\IpAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Http\ClientIpResolution
 * @covers \Mata\Http\ClientIpResolver
 *
 * @internal
 */
class ClientIpResolverTest extends TestCase
{
    private ClientIpResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ClientIpResolver();
    }

    public function testUsesRemoteAddrWhenNoProxyConfigured(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        ], []);

        $this->assertSame('203.0.113.50', $result->clientIp);
        $this->assertFalse($result->usedTrustedProxy);
    }

    public function testIgnoresForwardedForFromUntrustedRemoteAddr(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
        ], ['10.0.0.1']);

        $this->assertSame('203.0.113.50', $result->clientIp);
        $this->assertFalse($result->usedTrustedProxy);
    }

    public function testUsesForwardedForFromTrustedProxy(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        ], ['10.0.0.1']);

        $this->assertSame('198.51.100.10', $result->clientIp);
        $this->assertTrue($result->usedTrustedProxy);
    }

    public function testUsesRightmostUntrustedForwardedIp(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'unknown, 198.51.100.10, 198.51.100.11',
        ], ['10.0.0.1']);

        $this->assertSame('198.51.100.11', $result->clientIp);
        $this->assertTrue($result->usedTrustedProxy);
    }

    public function testFallsBackToRemoteAddrWhenForwardedForIsMalformed(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'unknown, also-bad',
        ], ['10.0.0.1']);

        $this->assertSame('10.0.0.1', $result->clientIp);
        $this->assertFalse($result->usedTrustedProxy);
    }

    public function testIpv6RemoteAddrWorks(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '2001:db8::1',
        ], []);

        $this->assertSame('2001:db8::1', $result->clientIp);
        $this->assertFalse($result->usedTrustedProxy);
    }

    public function testSpoofedForwardedForDoesNotBypassAllowlistWhenRemoteAddrIsUntrusted(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
        ], ['10.0.0.1']);

        $this->assertSame('203.0.113.50', $result->clientIp);
        $this->assertFalse($result->usedTrustedProxy);
    }

    public function testSpoofedForwardedForDoesNotBypassAllowlistWhenTrustedProxyAppendsClientIp(): void
    {
        $result = $this->resolver->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1, 203.0.113.50',
        ], ['10.0.0.1']);

        $this->assertSame('203.0.113.50', $result->clientIp);
        $this->assertFalse((new IpAllowlist())->isAllowed($result->clientIp, ['127.0.0.1']));
    }
}
