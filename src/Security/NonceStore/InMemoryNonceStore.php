<?php

declare(strict_types=1);

namespace Mata\Security\NonceStore;

final class InMemoryNonceStore implements NonceStoreInterface
{
    /** @var array<string,int> */
    private static array $nonces = [];

    public function consume(string $nonce, int $expiresAt): bool
    {
        $this->cleanup();

        if (isset(self::$nonces[$nonce])) {
            return false;
        }

        self::$nonces[$nonce] = $expiresAt;

        return true;
    }

    public function count(): int
    {
        $this->cleanup();

        return count(self::$nonces);
    }

    public function clear(): void
    {
        self::$nonces = [];
    }

    private function cleanup(): void
    {
        $now = time();
        self::$nonces = array_filter(
            self::$nonces,
            static fn (int $expiry): bool => $expiry > $now
        );
    }
}
