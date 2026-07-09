<?php

declare(strict_types=1);

namespace Mata\Security\NonceStore;

interface NonceStoreInterface
{
    /**
     * Atomically mark a nonce as used until the given Unix timestamp.
     *
     * Returns false when the nonce is already present and unexpired.
     */
    public function consume(string $nonce, int $expiresAt): bool;

    public function count(): int;

    public function clear(): void;
}
