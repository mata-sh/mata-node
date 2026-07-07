<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

final class UnconfiguredSessionBackend implements SessionBackendInterface
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function getMetadata(array $config, string $rootPath, ?string $cachePath = null, ?string $cacheIdentity = null): array
    {
        return [
            'type' => 'none',
            'status' => 'unconfigured',
            'count_last_hour' => null,
            'count_last_day' => null,
            'count_total' => null,
            'path' => null,
            'size_kb' => null,
        ];
    }
}
