<?php

declare(strict_types=1);

namespace Mata\Core;

use Mata\Config\Dto\AuthConfig;

/**
 * HMAC-based authentication system.
 *
 * Validates incoming requests using X-Mata-Auth header with format:
 * <server_id>|<unix_timestamp>|<nonce>|<hex_hmac_sha256>
 *
 * Provides protection against:
 * - Unauthorized access (HMAC signature validation)
 * - Replay attacks (nonce tracking)
 * - Clock skew issues (configurable time window)
 */
class Auth
{
    /** @var array<string,int> In-memory nonce cache: nonce => expiry_timestamp */
    private static array $nonceCache = [];

    private const MIN_NONCE_LENGTH = 16;
    private const MAX_NONCE_LENGTH = 64;

    public function __construct(
        private readonly AuthConfig $authConfig,
    ) {}

    /**
     * Validate the HMAC authentication header.
     *
     * @return array{success: bool, error?: string, serverId?: string}
     */
    public function validateHmacAuth(): array
    {
        // 1. Read X-Mata-Auth header
        $authHeader = $this->getAuthHeader();
        if ($authHeader === null) {
            return ['success' => false, 'error' => 'Missing X-Mata-Auth header'];
        }

        // 2. Parse header format: serverId|timestamp|nonce|signature
        $parts = explode('|', $authHeader);
        if (count($parts) !== 4) {
            return ['success' => false, 'error' => 'Invalid X-Mata-Auth header format'];
        }

        [$serverId, $timestampStr, $nonce, $signature] = $parts;

        // 3. Validate timestamp
        $timestamp = filter_var($timestampStr, FILTER_VALIDATE_INT);
        if ($timestamp === false) {
            return ['success' => false, 'error' => 'Invalid timestamp format'];
        }

        $now = time();
        $timeDiff = abs($now - $timestamp);
        if ($timeDiff > $this->authConfig->clockSkewWindow) {
            return [
                'success' => false,
                'error' => sprintf('Timestamp outside allowed window (%d seconds)', $this->authConfig->clockSkewWindow),
            ];
        }

        // 4. Validate server ID matches expected
        if ($serverId !== $this->authConfig->dashboardServerId) {
            return ['success' => false, 'error' => 'Invalid server ID'];
        }

        // 5. Validate nonce format and check for replay
        $nonceValidation = $this->validateNonce($nonce, $timestamp);
        if (!$nonceValidation['success']) {
            return $nonceValidation;
        }

        // 6. Compute expected HMAC
        $payload = $serverId . '|' . $timestampStr . '|' . $nonce;
        $expectedSignature = hash_hmac('sha256', $payload, $this->authConfig->dashboardApiKey);

        // 7. Compare signatures in constant time
        if (!hash_equals($expectedSignature, $signature)) {
            return ['success' => false, 'error' => 'Invalid HMAC signature'];
        }

        // 8. Store nonce to prevent replay
        $this->storeNonce($nonce, $timestamp);

        return ['success' => true, 'serverId' => $serverId];
    }

    /**
     * Get the X-Mata-Auth header from the request.
     */
    private function getAuthHeader(): ?string
    {
        // Try direct header access first
        if (isset($_SERVER['HTTP_X_MATA_AUTH'])) {
            return $_SERVER['HTTP_X_MATA_AUTH'];
        }

        // Fallback to apache_request_headers if available
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['X-Mata-Auth'])) {
                return $headers['X-Mata-Auth'];
            }
        }

        return null;
    }

    /**
     * Validate nonce format and check for replay attacks.
     *
     * @return array{success: bool, error?: string}
     */
    private function validateNonce(string $nonce, int $timestamp): array
    {
        // Check nonce length
        $nonceLength = strlen($nonce);
        if ($nonceLength < self::MIN_NONCE_LENGTH || $nonceLength > self::MAX_NONCE_LENGTH) {
            return [
                'success' => false,
                'error' => sprintf('Nonce length must be between %d and %d characters', self::MIN_NONCE_LENGTH, self::MAX_NONCE_LENGTH),
            ];
        }

        // Clean up expired nonces from cache
        $this->cleanupExpiredNonces();

        // Check if nonce was already used
        if (isset(self::$nonceCache[$nonce])) {
            return ['success' => false, 'error' => 'Nonce already used (replay attack detected)'];
        }

        return ['success' => true];
    }

    /**
     * Store nonce in cache to prevent replay attacks.
     */
    private function storeNonce(string $nonce, int $timestamp): void
    {
        // Nonces expire after twice the clock skew window
        $expiry = $timestamp + ($this->authConfig->clockSkewWindow * 2);
        self::$nonceCache[$nonce] = $expiry;
    }

    /**
     * Remove expired nonces from cache to prevent memory bloat.
     */
    private function cleanupExpiredNonces(): void
    {
        $now = time();
        self::$nonceCache = array_filter(
            self::$nonceCache,
            static fn (int $expiry): bool => $expiry > $now
        );
    }

    /**
     * Get the current nonce cache size (useful for monitoring).
     */
    public function getNonceCacheSize(): int
    {
        return count(self::$nonceCache);
    }

    /**
     * Clear all nonces from cache (useful for testing).
     */
    public function clearNonceCache(): void
    {
        self::$nonceCache = [];
    }
}
