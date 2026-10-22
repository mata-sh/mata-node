<?php

declare(strict_types=1);

namespace Mata\Services;

use Mata\Config\Dto\RateLimitingConfig;
use MataSh\RateLimiter\RateLimiter as MataShRateLimiter;
use MataSh\RateLimiter\RateLimitIdentifier;
use MataSh\RateLimiter\RateLimitResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Wrapper service for mata-sh/rate-limiter package.
 *
 * Provides application-specific rate limiting logic with
 * route group awareness and IP-based identification.
 */
class RateLimiter
{
    private ?MataShRateLimiter $rateLimiter;
    private RateLimitingConfig $config;
    private LoggerInterface $logger;

    public function __construct(
        ?MataShRateLimiter $rateLimiter,
        RateLimitingConfig $config,
        LoggerInterface $logger,
    ) {
        $this->rateLimiter = $rateLimiter;
        $this->config = $config;
        $this->logger = $logger;

        $this->rateLimiter?->setRateLimitingEnabled($config->enabled);
    }

    /**
     * Atomically consume one request for a route group.
     */
    public function consume(string $group, ?string $ip = null): RateLimitResult
    {
        if (!$this->config->enabled) {
            return new RateLimitResult(true, 0, null);
        }

        $limits = $this->config->getLimitForGroup($group);

        try {
            return $this->requireRateLimiter()->consume(
                $this->identifier($group, $ip),
                $limits['maxRequests'],
                $limits['windowSeconds']
            );
        } catch (Throwable $e) {
            $this->logger->error('Rate-limit request denied after limiter failure', [
                'group' => $group,
                'exception' => $e,
            ]);

            return new RateLimitResult(
                false,
                $limits['maxRequests'],
                time() + $limits['windowSeconds'],
            );
        }
    }

    /**
     * Whether the group's limit is used up, without consuming a request; fails closed like consume().
     */
    public function isExhausted(string $group, ?string $ip = null): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        $limits = $this->config->getLimitForGroup($group);

        try {
            return !$this->requireRateLimiter()->check(
                $this->identifier($group, $ip),
                $limits['maxRequests'],
                $limits['windowSeconds']
            )['allowed'];
        } catch (Throwable $e) {
            $this->logger->error('Rate-limit request denied after limiter failure', [
                'group' => $group,
                'exception' => $e,
            ]);

            return true;
        }
    }

    public function getLimit(string $group): int
    {
        return $this->config->getLimitForGroup($group)['maxRequests'];
    }

    public function getWindowSeconds(string $group): int
    {
        return $this->config->getLimitForGroup($group)['windowSeconds'];
    }

    /**
     * Get underlying RateLimiter instance.
     */
    public function getRateLimiter(): MataShRateLimiter
    {
        return $this->requireRateLimiter();
    }

    private function identifier(string $group, ?string $ip): string
    {
        return $group . '_' . RateLimitIdentifier::fromIp($ip);
    }

    private function requireRateLimiter(): MataShRateLimiter
    {
        if ($this->rateLimiter === null) {
            throw new RuntimeException('Rate limiter is unavailable');
        }

        return $this->rateLimiter;
    }
}
