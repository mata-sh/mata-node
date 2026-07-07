<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\SessionBackends;

use Mata\Watchers\SessionBackends\RedisSessionBackend;
use Mata\Watchers\SessionBackends\RedisSessionClientFactoryInterface;
use Mata\Watchers\SessionBackends\RedisSessionClientInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Mata\Watchers\SessionBackends\RedisSessionBackend
 *
 * @internal
 */
class RedisSessionBackendTest extends TestCase
{
    public function testCountsRedisSessionsWithScan(): void
    {
        $client = new FakeRedisSessionClient([
            ['cursor' => '2', 'keys' => ['PHPREDIS_SESSION:a', 'PHPREDIS_SESSION:b']],
            ['cursor' => '0', 'keys' => ['PHPREDIS_SESSION:c']],
        ]);
        $backend = new RedisSessionBackend(new FakeRedisSessionClientFactory($client));

        $metadata = $backend->getMetadata([
            'prefix' => 'PHPREDIS_SESSION:',
            'scan_count' => 25,
        ], __DIR__);

        $this->assertSame([
            'type' => 'redis',
            'status' => 'ok',
            'count_last_hour' => null,
            'count_last_day' => null,
            'count_total' => 3,
            'path' => null,
            'size_kb' => null,
        ], $metadata);
        $this->assertSame([
            ['cursor' => '0', 'match' => 'PHPREDIS_SESSION:*', 'count' => 25],
            ['cursor' => '2', 'match' => 'PHPREDIS_SESSION:*', 'count' => 25],
        ], $client->calls);
        $this->assertTrue($client->closed);
    }

    public function testRedisConnectionFailureReturnsErrorMetadata(): void
    {
        $backend = new RedisSessionBackend(new FakeRedisSessionClientFactory(null, new RuntimeException('Connection failed')));

        $metadata = $backend->getMetadata([], __DIR__);

        $this->assertSame('redis', $metadata['type']);
        $this->assertSame('error', $metadata['status']);
        $this->assertSame('Connection failed', $metadata['error']);
        $this->assertNull($metadata['count_last_hour']);
        $this->assertNull($metadata['count_last_day']);
        $this->assertNull($metadata['count_total']);
        $this->assertNull($metadata['path']);
        $this->assertNull($metadata['size_kb']);
    }
}

final class FakeRedisSessionClientFactory implements RedisSessionClientFactoryInterface
{
    public function __construct(
        private ?RedisSessionClientInterface $client,
        private ?RuntimeException $exception = null
    ) {}

    public function create(array $config): RedisSessionClientInterface
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->client === null) {
            throw new RuntimeException('Missing fake Redis client');
        }

        return $this->client;
    }
}

final class FakeRedisSessionClient implements RedisSessionClientInterface
{
    /** @var array<int, array{cursor: string, keys: array<int, string>}> */
    private array $responses;

    /** @var array<int, array{cursor: string, match: string, count: int}> */
    public array $calls = [];

    public bool $closed = false;

    /**
     * @param array<int, array{cursor: string, keys: array<int, string>}> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function scan(string $cursor, string $match, int $count): array
    {
        $this->calls[] = [
            'cursor' => $cursor,
            'match' => $match,
            'count' => $count,
        ];

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new RuntimeException('Missing fake Redis response');
        }

        return $response;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
