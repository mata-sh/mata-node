<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\SessionBackends;

use Mata\Watchers\SessionBackends\RedisSocketClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \Mata\Watchers\SessionBackends\RedisSocketClient
 *
 * @internal
 */
class RedisSocketClientTest extends TestCase
{
    public function testRedisErrorReplyIncludesServerMessageAndConsumesLine(): void
    {
        $socket = fopen('php://temp', 'r+');
        self::assertIsResource($socket);
        fwrite($socket, "-NOAUTH Authentication required.\r\n+OK\r\n");
        rewind($socket);

        $reflection = new ReflectionClass(RedisSocketClient::class);
        $client = $reflection->newInstanceWithoutConstructor();
        $socketProperty = $reflection->getProperty('socket');
        $socketProperty->setValue($client, $socket);

        $readResponse = $reflection->getMethod('readResponse');

        try {
            $readResponse->invoke($client);
            self::fail('Expected Redis error reply to throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('Redis command failed: NOAUTH Authentication required.', $exception->getMessage());
        }

        self::assertSame("+OK\r\n", stream_get_contents($socket));
    }
}
