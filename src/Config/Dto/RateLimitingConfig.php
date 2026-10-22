<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

/**
 * Immutable DTO representing rate limiting configuration.
 *
 * Contains settings for rate limiting including storage backend,
 * connection parameters, and per-route-group limits.
 */
final class RateLimitingConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $storageType,
        public readonly ?string $filePath,
        public readonly ?string $redisHost,
        public readonly int $redisPort,
        public readonly ?string $redisPassword,
        public readonly int $redisDatabase,
        public readonly int $apiMaxRequests,
        public readonly int $apiWindowSeconds,
        public readonly int $authFailedMaxRequests,
        public readonly int $authFailedWindowSeconds,
    ) {}

    /**
     * Get rate limit configuration for specific route group.
     *
     * @param string $group Route group: 'api' or 'auth_failed'
     *
     * @return array{maxRequests: int, windowSeconds: int}
     */
    public function getLimitForGroup(string $group): array
    {
        return match ($group) {
            'api' => [
                'maxRequests' => $this->apiMaxRequests,
                'windowSeconds' => $this->apiWindowSeconds,
            ],
            'auth_failed' => [
                'maxRequests' => $this->authFailedMaxRequests,
                'windowSeconds' => $this->authFailedWindowSeconds,
            ],
            default => throw new InvalidArgumentException("Unknown rate limit group: {$group}"),
        };
    }
}
