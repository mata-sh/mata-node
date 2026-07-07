<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

final class SessionBackendFactory
{
    public function __construct(
        private ?RedisSessionClientFactoryInterface $redisClientFactory = null
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config): SessionBackendInterface
    {
        $type = $this->resolveType($config);

        return match ($type) {
            'file' => new FileSessionBackend(),
            'redis' => new RedisSessionBackend($this->redisClientFactory ?? new RedisSocketClientFactory()),
            default => new UnsupportedSessionBackend($type),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveType(array $config): string
    {
        $type = $config['type'] ?? 'file';
        if (!is_string($type) || trim($type) === '') {
            return 'file';
        }

        return strtolower(trim($type));
    }
}
