<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

interface RedisSessionClientFactoryInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config): RedisSessionClientInterface;
}
