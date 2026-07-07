<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\SessionBackends;

use Mata\Watchers\SessionBackends\FileSessionBackend;
use Mata\Watchers\SessionBackends\RedisSessionBackend;
use Mata\Watchers\SessionBackends\SessionBackendFactory;
use Mata\Watchers\SessionBackends\UnconfiguredSessionBackend;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\SessionBackends\SessionBackendFactory
 *
 * @internal
 */
class SessionBackendFactoryTest extends TestCase
{
    public function testCreatesUnconfiguredBackendForEmptyConfig(): void
    {
        $backend = (new SessionBackendFactory())->create([]);

        $this->assertInstanceOf(UnconfiguredSessionBackend::class, $backend);
        $this->assertSame([
            'type' => 'none',
            'status' => 'unconfigured',
            'count_last_hour' => null,
            'count_last_day' => null,
            'count_total' => null,
            'path' => null,
            'size_kb' => null,
        ], $backend->getMetadata([], __DIR__));
    }

    public function testCreatesFileBackendWhenConfigHasNoType(): void
    {
        $backend = (new SessionBackendFactory())->create(['path' => 'sessions']);

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
