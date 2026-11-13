<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Security\IpAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Security\IpAllowlist
 *
 * @internal
 */
class IpAllowlistTest extends TestCase
{
    private IpAllowlist $allowlist;

    protected function setUp(): void
    {
        $this->allowlist = new IpAllowlist();
    }

    public function testExactIpv4Allowed(): void
    {
        $this->assertTrue($this->allowlist->isAllowed('198.51.100.10', ['198.51.100.10']));
    }

    public function testExactIpv4Denied(): void
    {
        $this->assertFalse($this->allowlist->isAllowed('198.51.100.11', ['198.51.100.10']));
    }

    public function testExactIpv6Allowed(): void
    {
        $this->assertTrue($this->allowlist->isAllowed('2001:db8::1', ['2001:db8:0:0:0:0:0:1']));
    }

    public function testIpv4CidrAllowed(): void
    {
        $this->assertTrue($this->allowlist->isAllowed('198.51.100.50', ['198.51.100.0/24']));
    }

    public function testIpv4CidrDenied(): void
    {
        $this->assertFalse($this->allowlist->isAllowed('198.51.101.50', ['198.51.100.0/24']));
    }

    public function testIpv6CidrAllowed(): void
    {
        $this->assertTrue($this->allowlist->isAllowed('2001:db8::abcd', ['2001:db8::/32']));
    }

    public function testInvalidEntryIsRejected(): void
    {
        $this->assertFalse(IpAllowlist::isValidEntry('198.51.100.0/999'));
        $this->assertFalse(IpAllowlist::isValidEntry('not-an-ip'));
    }
}
