<?php

declare(strict_types=1);

namespace Mata\Factory;

use Mata\Config\Dto\RateLimitingConfig;
use Mata\Services\RateLimiter;
use MataSh\RateLimiter\RateLimiter as MataShRateLimiter;
use MataSh\RateLimiter\Storage\ApcuStorage;
use MataSh\RateLimiter\Storage\FileStorage;
use MataSh\RateLimiter\Storage\RedisStorage;
use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Factory for creating RateLimiter instances.
 *
 * Handles initialization of rate limiter with different storage backends
 * (file, Redis) based on configuration.
 */
final class RateLimiterFactory
{
    /**
     * Create RateLimiter from configuration.
     *
     * @param RateLimitingConfig $config Configuration for rate limiting
     * @param LoggerInterface    $logger Logger for initialization messages
     *
     * @return null|RateLimiter Rate limiter, or null when disabled
     */
    public static function create(
        RateLimitingConfig $config,
        LoggerInterface $logger
    ): ?RateLimiter {
        if (!$config->enabled) {
            return null;
        }

        try {
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

                $storage = new RedisStorage($redis);
            } else {
                $storage = match ($config->storageType) {
                    'file' => new FileStorage($config->filePath),
                    'apcu' => new ApcuStorage(),
                    default => throw new RuntimeException('Unsupported rate limiter storage: ' . $config->storageType),
                };
            }

            // Initialize RateLimiter with configured backend
            $packageRateLimiter = new MataShRateLimiter(
                storage: $storage,
                logger: $logger
            );

            $rateLimiter = new RateLimiter($packageRateLimiter, $config, $logger);

            $logger->info('Rate limiting initialized', [
                'storage' => $config->storageType,
                'enabled' => $config->enabled,
            ]);

            return $rateLimiter;
        } catch (Throwable $e) {
            $logger->critical('Failed to initialize rate limiter; all protected requests will be denied', [
                'storage' => $config->storageType,
                'exception' => $e,
            ]);

            return new RateLimiter(null, $config, $logger);
        }
    }
}
