<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

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
        public readonly int $loginMaxRequests,
        public readonly int $loginWindowSeconds,
        public readonly int $dashboardMaxRequests,
        public readonly int $dashboardWindowSeconds,
        public readonly int $apiMaxRequests,
        public readonly int $apiWindowSeconds,
        public readonly int $authFailedMaxRequests,
        public readonly int $authFailedWindowSeconds,
    ) {}

    /**
     * Get rate limit configuration for specific route group.
     *
     * @param string $group Route group: 'login', 'dashboard', 'api', 'auth_failed'
     *
     * @return array{maxRequests: int, windowSeconds: int}
     */
    public function getLimitForGroup(string $group): array
    {
        return match ($group) {
            'login' => [
                'maxRequests' => $this->loginMaxRequests,
                'windowSeconds' => $this->loginWindowSeconds,
            ],
            'dashboard' => [
                'maxRequests' => $this->dashboardMaxRequests,
                'windowSeconds' => $this->dashboardWindowSeconds,
            ],
            'api' => [
                'maxRequests' => $this->apiMaxRequests,
                'windowSeconds' => $this->apiWindowSeconds,
            ],
            'auth_failed' => [
                'maxRequests' => $this->authFailedMaxRequests,
                'windowSeconds' => $this->authFailedWindowSeconds,
            ],
            default => [
                'maxRequests' => 60,
                'windowSeconds' => 60,
            ],
        };
    }
}
