<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

interface SessionBackendInterface
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function getMetadata(array $config, string $rootPath, ?string $cachePath = null, ?string $cacheIdentity = null): array;
}
