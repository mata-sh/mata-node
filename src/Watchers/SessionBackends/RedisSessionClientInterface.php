<?php

declare(strict_types=1);

namespace Mata\Watchers\SessionBackends;

interface RedisSessionClientInterface
{
    /**
     * @return array{cursor: string, keys: array<int, string>}
     */
    public function scan(string $cursor, string $match, int $count): array;

    public function get(string $key): ?string;

    public function close(): void;
}
