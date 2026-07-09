<?php

declare(strict_types=1);

namespace Mata\Factory;

use Mata\Security\NonceStore\FileNonceStore;
use Mata\Security\NonceStore\NonceStoreInterface;

/**
 * Factory for creating nonce store instances.
 *
 * Centralizes the storage-path convention for HMAC replay-protection nonces and
 * provides a single seam for swapping the backend (file today, Redis later),
 * mirroring RateLimiterFactory.
 */
final class NonceStoreFactory
{
    private const SUBDIR = 'auth_nonces';

    /**
     * Create the nonce store rooted under the given cache directory.
     *
     * @param string $cacheDir Base cache directory (e.g. PathResolver::$cachePath)
     */
    public static function create(string $cacheDir): NonceStoreInterface
    {
        return new FileNonceStore(rtrim($cacheDir, '/') . '/' . self::SUBDIR);
    }
}
