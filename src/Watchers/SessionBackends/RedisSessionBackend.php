<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

use Throwable;

final class RedisSessionBackend implements SessionBackendInterface
{
    private const DEFAULT_PREFIX = 'PHPREDIS_SESSION:';
    private const DEFAULT_SCAN_COUNT = 1000;

    public function __construct(
        private RedisSessionClientFactoryInterface $clientFactory
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function getMetadata(array $config, string $rootPath): array
    {
        $metadata = $this->baseMetadata();
        $client = null;

        try {
            $client = $this->clientFactory->create($config);
            $metadata['count_total'] = $this->countKeys($client, $config);
        } catch (Throwable $e) {
            $metadata['status'] = 'error';
            $metadata['error'] = $e->getMessage();
            $metadata['count_total'] = null;
        } finally {
            $client?->close();
        }

        return $metadata;
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
    private function resolveScanCount(array $config): int
    {
        return is_numeric($config['scan_count'] ?? null) ? max(1, (int) $config['scan_count']) : self::DEFAULT_SCAN_COUNT;
    }
}
