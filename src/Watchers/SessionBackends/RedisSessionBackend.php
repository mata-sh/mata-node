<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

use RuntimeException;
use Throwable;

final class RedisSessionBackend implements SessionBackendInterface
{
    private const DEFAULT_PREFIX = 'PHPREDIS_SESSION:';
    private const DEFAULT_SCAN_COUNT = 1000;
    private const DEFAULT_CACHE_TTL_SECONDS = 60;

    public function __construct(
        private RedisSessionClientFactoryInterface $clientFactory
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function getMetadata(array $config, string $rootPath, ?string $cachePath = null): array
    {
        $countKey = $this->resolveCountKey($config);
        if ($countKey === null) {
            $cached = $this->readCache($config, $cachePath);
            if ($cached !== null) {
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
            if ($countKey === null) {
                $this->writeCache($config, $cachePath, $metadata);
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
     * @return null|array<string, mixed>
     */
    private function readCache(array $config, ?string $cachePath): ?array
    {
        $cacheFile = $this->cacheFile($config, $cachePath);
        if ($cacheFile === null || !is_file($cacheFile)) {
            return null;
        }

        $ttl = $this->resolveCacheTtlSeconds($config);
        $cacheMtime = filemtime($cacheFile);
        if ($ttl <= 0 || $cacheMtime === false || time() - $cacheMtime > $ttl) {
            return null;
        }

        $data = json_decode((string) file_get_contents($cacheFile), true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeCache(array $config, ?string $cachePath, array $metadata): void
    {
        $cacheFile = $this->cacheFile($config, $cachePath);
        if ($cacheFile === null || $this->resolveCacheTtlSeconds($config) <= 0) {
            return;
        }

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return;
        }

        @file_put_contents($cacheFile, json_encode($metadata), LOCK_EX);
    }

    private function cacheFile(array $config, ?string $cachePath): ?string
    {
        if ($cachePath === null || $cachePath === '') {
            return null;
        }

        $cacheConfig = $config;
        unset($cacheConfig['cache_ttl_seconds']);
        ksort($cacheConfig);

        return rtrim($cachePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'sessions'
            . DIRECTORY_SEPARATOR
            . 'redis-'
            . sha1(json_encode($cacheConfig) ?: '')
            . '.json';
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
