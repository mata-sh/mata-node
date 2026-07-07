<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

final class RedisSocketClientFactory implements RedisSessionClientFactoryInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config): RedisSessionClientInterface
    {
        return RedisSocketClient::fromConfig($config);
    }
}
