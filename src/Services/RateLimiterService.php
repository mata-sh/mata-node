<?php

declare(strict_types=1);

namespace Mata\Services\Foundation;

use Mata\Config\Dto\RateLimitingConfig;
use MataSh\RateLimiter\RateLimiter;
use MataSh\RateLimiter\RateLimitIdentifier;

/**
 * Wrapper service for mata-sh/rate-limiter package.
 *
 * Provides application-specific rate limiting logic with
 * route group awareness and IP-based identification.
 */
class RateLimiterService
{
    private RateLimiter $rateLimiter;
    private RateLimitingConfig $config;

    public function __construct(RateLimiter $rateLimiter, RateLimitingConfig $config)
    {
        $this->rateLimiter = $rateLimiter;
        $this->config = $config;

        // Apply global enable/disable setting
        $this->rateLimiter->setRateLimitingEnabled($config->enabled);
    }

    /**
     * Check if request should be rate limited for specific route group.
     *
     * @param string      $group Route group: 'login', 'dashboard', 'api'
     * @param null|string $ip    Optional IP address (defaults to $_SERVER['REMOTE_ADDR'])
     *
     * @return bool True if rate limit exceeded, false if within limits
     */
    public function isRateLimited(string $group, ?string $ip = null): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        $identifier = RateLimitIdentifier::fromIp($ip);
        $limits = $this->config->getLimitForGroup($group);

        // allow() returns true if allowed, we need to return true if limited
        return !$this->rateLimiter->allow(
            $identifier,
            $limits['maxRequests'],
            $limits['windowSeconds']
        );
    }

    /**
     * Get current rate limit status for route group.
     *
     * @param string      $group Route group: 'login', 'dashboard', 'api'
     * @param null|string $ip    Optional IP address
     *
     * @return array{allowed: bool, current: int, limit: int, remaining: int, reset_at: int, storage_type: string}
     */
    public function check(string $group, ?string $ip = null): array
    {
        $identifier = RateLimitIdentifier::fromIp($ip);
        $limits = $this->config->getLimitForGroup($group);

        return $this->rateLimiter->check(
            $identifier,
            $limits['maxRequests'],
            $limits['windowSeconds']
        );
    }

    /**
     * Get underlying RateLimiter instance.
     */
    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }
}
