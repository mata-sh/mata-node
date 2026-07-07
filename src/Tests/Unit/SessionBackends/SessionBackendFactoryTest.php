<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\SessionBackends;

use Mata\Watchers\SessionBackends\FileSessionBackend;
use Mata\Watchers\SessionBackends\RedisSessionBackend;
use Mata\Watchers\SessionBackends\SessionBackendFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\SessionBackends\SessionBackendFactory
 *
 * @internal
 */
class SessionBackendFactoryTest extends TestCase
{
    public function testCreatesFileBackendByDefault(): void
    {
        $backend = (new SessionBackendFactory())->create([]);

        $this->assertInstanceOf(FileSessionBackend::class, $backend);
    }

    public function testCreatesRedisBackend(): void
    {
        $backend = (new SessionBackendFactory())->create(['type' => 'redis']);

        $this->assertInstanceOf(RedisSessionBackend::class, $backend);
    }

    public function testUnknownBackendReturnsUnsupportedMetadata(): void
    {
        $backend = (new SessionBackendFactory())->create(['type' => 'memcached']);

        $metadata = $backend->getMetadata([], __DIR__);

        $this->assertSame('memcached', $metadata['type']);
        $this->assertSame('unsupported', $metadata['status']);
        $this->assertSame('Unsupported session backend: memcached', $metadata['error']);
        $this->assertNull($metadata['count_total']);
    }
}
