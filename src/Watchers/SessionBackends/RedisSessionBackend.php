<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

use Mata\Services\FileCache;
use RuntimeException;
use Throwable;

final class RedisSessionBackend implements SessionBackendInterface
{
    private const DEFAULT_PREFIX = 'PHPREDIS_SESSION:';
    private const DEFAULT_SCAN_COUNT = 1000;
    private const DEFAULT_CACHE_TTL_SECONDS = 60;

    public function __construct(
        private RedisSessionClientFactoryInterface $clientFactory,
        private ?FileCache $cache = null
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function getMetadata(array $config, string $rootPath, ?string $cacheIdentity = null): array
    {
        $countKey = $this->resolveCountKey($config);
        $cacheKey = null;
        $cacheTtlSeconds = $this->resolveCacheTtlSeconds($config);

        if ($countKey === null) {
            $cacheKey = $this->cacheKey($config, $cacheIdentity);
            $cached = $this->cache?->get($cacheKey, $cacheTtlSeconds);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $metadata = $this->baseMetadata();
        $client = null;

        try {
            $client = $this->clientFactory->create($config);
            $metadata['count_total'] = $countKey !== null
                ? $this->countFromCounterKey($client, $countKey)
                : $this->countKeys($client, $config);
            if ($countKey === null && $cacheTtlSeconds > 0 && $cacheKey !== null) {
                $this->cache?->set($cacheKey, $metadata);
            }
        } catch (Throwable $e) {
            $metadata['status'] = 'error';
            $metadata['error'] = $e->getMessage();
            $metadata['count_total'] = null;
        } finally {
            $client?->close();
        }

        return $metadata;
    }

    private function countFromCounterKey(RedisSessionClientInterface $client, string $countKey): int
    {
        $value = $client->get($countKey);
        if ($value === null || $value === '') {
            return 0;
        }

        if (!ctype_digit($value)) {
            throw new RuntimeException('Invalid Redis session count_key value');
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function countKeys(RedisSessionClientInterface $client, array $config): int
    {
        $cursor = '0';
        $count = 0;
        $match = $this->resolvePrefix($config) . '*';
        $scanCount = $this->resolveScanCount($config);

        do {
            $result = $client->scan($cursor, $match, $scanCount);
            $cursor = $result['cursor'];
            $count += count($result['keys']);
        } while ($cursor !== '0');

        return $count;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function cacheKey(array $config, ?string $cacheIdentity): string
    {
        return 'sessions/redis-' . sha1(json_encode([
            'connection' => $this->cacheConnectionIdentity($config),
            'identity' => $cacheIdentity,
        ]) ?: '');
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function cacheConnectionIdentity(array $config): array
    {
        $dsnIdentity = $this->dsnConnectionIdentity($config);

        return [
            'type' => 'redis',
            'dsn' => $dsnIdentity,
            'dsn_env' => $this->scalarConfig($config, 'dsn_env'),
            'host' => $dsnIdentity['host'] ?? $this->scalarConfig($config, 'host'),
            'port' => $dsnIdentity['port'] ?? $this->scalarConfig($config, 'port'),
            'database' => $dsnIdentity['database'] ?? $this->scalarConfig($config, 'database'),
            'prefix' => $this->resolvePrefix($config),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function dsnConnectionIdentity(array $config): array
    {
        $dsn = $this->scalarConfig($config, 'dsn');
        if ($dsn === null || $dsn === '') {
            return [];
        }

        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            return ['present' => true];
        }

        return [
            'scheme' => $parts['scheme'] ?? null,
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'database' => isset($parts['path']) ? ltrim((string) $parts['path'], '/') : null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function scalarConfig(array $config, string $key): ?string
    {
        return is_scalar($config[$key] ?? null) ? (string) $config[$key] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseMetadata(): array
    {
        return [
            'type' => 'redis',
            'status' => 'ok',
            'count_last_hour' => null,
            'count_last_day' => null,
            'count_total' => 0,
            'path' => null,
            'size_kb' => null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolvePrefix(array $config): string
    {
        return is_scalar($config['prefix'] ?? null) ? (string) $config['prefix'] : self::DEFAULT_PREFIX;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveCountKey(array $config): ?string
    {
        $countKey = $config['count_key'] ?? null;
        if (!is_scalar($countKey)) {
            return null;
        }

        $countKey = trim((string) $countKey);

        return $countKey === '' ? null : $countKey;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveScanCount(array $config): int
    {
        return is_numeric($config['scan_count'] ?? null) ? max(1, (int) $config['scan_count']) : self::DEFAULT_SCAN_COUNT;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveCacheTtlSeconds(array $config): int
    {
        return is_numeric($config['cache_ttl_seconds'] ?? null)
            ? max(0, (int) $config['cache_ttl_seconds'])
            : self::DEFAULT_CACHE_TTL_SECONDS;
    }
}
