<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\SessionBackends;

use Mata\Services\FileCache;
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
        ], __DIR__, 'testapp');

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

        $metadata = $backend->getMetadata([], __DIR__, 'testapp');

        $this->assertSame('redis', $metadata['type']);
        $this->assertSame('error', $metadata['status']);
        $this->assertSame('Connection failed', $metadata['error']);
        $this->assertNull($metadata['count_last_hour']);
        $this->assertNull($metadata['count_last_day']);
        $this->assertNull($metadata['count_total']);
        $this->assertNull($metadata['path']);
        $this->assertNull($metadata['size_kb']);
    }

    public function testCountsRedisSessionsFromCounterKeyWithoutScan(): void
    {
        $client = new FakeRedisSessionClient([], ['mata:sessions:testapp:count' => '42']);
        $backend = new RedisSessionBackend(new FakeRedisSessionClientFactory($client));

        $metadata = $backend->getMetadata([
            'count_key' => 'mata:sessions:testapp:count',
        ], __DIR__, 'testapp');

        $this->assertSame(42, $metadata['count_total']);
        $this->assertSame(['mata:sessions:testapp:count'], $client->getCalls);
        $this->assertSame([], $client->calls);
        $this->assertTrue($client->closed);
    }

    public function testMissingCounterKeyReportsZeroWithoutScan(): void
    {
        $client = new FakeRedisSessionClient([], []);
        $backend = new RedisSessionBackend(new FakeRedisSessionClientFactory($client));

        $metadata = $backend->getMetadata([
            'count_key' => 'mata:sessions:testapp:count',
        ], __DIR__, 'testapp');

        $this->assertSame(0, $metadata['count_total']);
        $this->assertSame(['mata:sessions:testapp:count'], $client->getCalls);
        $this->assertSame([], $client->calls);
    }

    public function testCachesRedisSessionMetadataAcrossCalls(): void
    {
        $cachePath = sys_get_temp_dir() . '/mata-node-redis-session-cache-' . bin2hex(random_bytes(6));
        mkdir($cachePath, 0777, true);

        try {
            $client = new FakeRedisSessionClient([
                ['cursor' => '0', 'keys' => ['PHPREDIS_SESSION:a', 'PHPREDIS_SESSION:b']],
            ]);
            $backend = new RedisSessionBackend(new FakeRedisSessionClientFactory($client), new FileCache($cachePath));
            $config = [
                'prefix' => 'PHPREDIS_SESSION:',
                'cache_ttl_seconds' => 60,
            ];

            $first = $backend->getMetadata($config, __DIR__, 'testapp');
            $second = $backend->getMetadata($config, __DIR__, 'testapp');

            $this->assertSame($first, $second);
            $this->assertSame(2, $second['count_total']);
            $this->assertCount(1, $client->calls);
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testRedisSessionCacheKeyDoesNotHashCredentials(): void
    {
        $cachePath = sys_get_temp_dir() . '/mata-node-redis-session-cache-' . bin2hex(random_bytes(6));
        mkdir($cachePath, 0777, true);

        try {
            $client = new FakeRedisSessionClient([
                ['cursor' => '0', 'keys' => ['PHPREDIS_SESSION:a']],
                ['cursor' => '0', 'keys' => ['PHPREDIS_SESSION:a', 'PHPREDIS_SESSION:b']],
            ]);
            $backend = new RedisSessionBackend(new FakeRedisSessionClientFactory($client), new FileCache($cachePath));
            $config = [
                'dsn' => 'redis://user:old-secret@127.0.0.1:6379/0',
                'prefix' => 'PHPREDIS_SESSION:',
                'cache_ttl_seconds' => 60,
            ];
            $rotatedCredentialsConfig = [
                'dsn' => 'redis://user:new-secret@127.0.0.1:6379/0',
                'prefix' => 'PHPREDIS_SESSION:',
                'cache_ttl_seconds' => 60,
            ];

            $first = $backend->getMetadata($config, __DIR__, 'testapp');
            $second = $backend->getMetadata($rotatedCredentialsConfig, __DIR__, 'testapp');

            $this->assertSame($first, $second);
            $this->assertSame(1, $second['count_total']);
            $this->assertCount(1, $client->calls);
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    public function testExplicitCacheIdentityPartitionsRedisSessionMetadataCache(): void
    {
        $cachePath = sys_get_temp_dir() . '/mata-node-redis-session-cache-' . bin2hex(random_bytes(6));
        mkdir($cachePath, 0777, true);

        try {
            $client = new FakeRedisSessionClient([
                ['cursor' => '0', 'keys' => ['PHPREDIS_SESSION:a']],
                ['cursor' => '0', 'keys' => ['PHPREDIS_SESSION:a', 'PHPREDIS_SESSION:b']],
            ]);
            $backend = new RedisSessionBackend(new FakeRedisSessionClientFactory($client), new FileCache($cachePath));
            $config = [
                'prefix' => 'PHPREDIS_SESSION:',
                'cache_ttl_seconds' => 60,
            ];

            $appAFirst = $backend->getMetadata($config, __DIR__, 'app-a');
            $appB = $backend->getMetadata($config, __DIR__, 'app-b');
            $appASecond = $backend->getMetadata($config, __DIR__, 'app-a');

            $this->assertSame(1, $appAFirst['count_total']);
            $this->assertSame(2, $appB['count_total']);
            $this->assertSame($appAFirst, $appASecond);
            $this->assertCount(2, $client->calls);
        } finally {
            $this->removeDirectory($cachePath);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
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

    /** @var array<string, string> */
    private array $values;

    /** @var array<int, array{cursor: string, match: string, count: int}> */
    public array $calls = [];

    /** @var array<int, string> */
    public array $getCalls = [];

    public bool $closed = false;

    /**
     * @param array<int, array{cursor: string, keys: array<int, string>}> $responses
     * @param array<string, string>                                       $values
     */
    public function __construct(array $responses, array $values = [])
    {
        $this->responses = $responses;
        $this->values = $values;
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

    public function get(string $key): ?string
    {
        $this->getCalls[] = $key;

        return $this->values[$key] ?? null;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
