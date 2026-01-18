<?php

declare(strict_types=1);

namespace Mata\Factory;

use Mata\Config\Dto\RateLimitingConfig;
use Mata\Services\Foundation\RateLimiterService;
use MataSh\RateLimiter\RateLimiter;
use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Factory for creating RateLimiterService instances.
 *
 * Handles initialization of rate limiter with different storage backends
 * (file, Redis) based on configuration.
 */
final class RateLimiterFactory
{
    /**
     * Create RateLimiterService from configuration.
     *
     * @param RateLimitingConfig $config Configuration for rate limiting
     * @param LoggerInterface    $logger Logger for initialization messages
     *
     * @return null|RateLimiterService Service instance or null if initialization fails
     */
    public static function create(
        RateLimitingConfig $config,
        LoggerInterface $logger
    ): ?RateLimiterService {
        try {
            $redis = null;

            // Initialize Redis if configured
            if ($config->storageType === 'redis') {
                $redis = new Redis();
                $connected = $redis->connect(
                    $config->redisHost,
                    $config->redisPort,
                    2.5  // timeout in seconds
                );

                if (!$connected) {
                    throw new RuntimeException('Failed to connect to Redis');
                }

                // Authenticate if password provided
                if (!empty($config->redisPassword)) {
                    $redis->auth($config->redisPassword);
                }

                // Select database
                $redis->select($config->redisDatabase);

                $logger->info('Rate limiter using Redis storage', [
                    'host' => $config->redisHost,
                    'port' => $config->redisPort,
                ]);
            }

            // Initialize RateLimiter with configured backend
            $rateLimiter = new RateLimiter(
                storageType: $config->storageType,
                fileStoragePath: $config->filePath,
                redis: $redis,
                logger: $logger
            );

            // Wrap in service layer
            $rateLimiterService = new RateLimiterService($rateLimiter, $config);

            $logger->info('Rate limiting initialized', [
                'storage' => $config->storageType,
                'enabled' => $config->enabled,
            ]);

            return $rateLimiterService;
        } catch (Throwable $e) {
            $logger->error('Failed to initialize rate limiter: ' . $e->getMessage());

            return null;
        }
    }
}
