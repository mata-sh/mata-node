<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\AuthConfig;
use Mata\Core\Auth;
use Mata\Security\NonceStore\InMemoryNonceStore;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HMAC-based authentication.
 *
 * @covers \Mata\Core\Auth
 *
 * @internal
 */
class AuthTest extends TestCase
{
    private Auth $auth;
    private AuthConfig $authConfig;
    private string $testServerId = 'dashboard';
    private string $testApiKey = 'test-api-key-for-hmac-validation';

    protected function setUp(): void
    {
        $this->authConfig = new AuthConfig(
            dashboardServerId: $this->testServerId,
            dashboardApiKey: $this->testApiKey,
            clockSkewWindow: 120
        );
        $this->auth = new Auth($this->authConfig, new InMemoryNonceStore());
        $this->auth->clearNonceCache();

        // Clear any existing X-Mata-Auth header
        unset($_SERVER['HTTP_X_MATA_AUTH']);
    }

    protected function tearDown(): void
    {
        $this->auth->clearNonceCache();
        unset($_SERVER['HTTP_X_MATA_AUTH']);
    }

    /**
     * Generate a valid HMAC auth header.
     */
    private function generateValidAuthHeader(?int $timestamp = null, ?string $nonce = null, ?string $serverId = null, ?string $apiKey = null): string
    {
        $serverId = $serverId ?? $this->testServerId;
        $apiKey = $apiKey ?? $this->testApiKey;
        $timestamp = $timestamp ?? time();
        $nonce = $nonce ?? bin2hex(random_bytes(16));

        $payload = $serverId . '|' . $timestamp . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, $apiKey);

        return $serverId . '|' . $timestamp . '|' . $nonce . '|' . $signature;
    }

    public function testValidAuthenticationSucceeds(): void
    {
        $authHeader = $this->generateValidAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertTrue($result['success']);
        $this->assertEquals($this->testServerId, $result['serverId']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function testMissingAuthHeaderFails(): void
    {
        unset($_SERVER['HTTP_X_MATA_AUTH']);

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertEquals('Missing X-Mata-Auth header', $result['error']);
    }

    public function testInvalidHeaderFormatFails(): void
    {
        // Only 3 parts instead of 4
        $_SERVER['HTTP_X_MATA_AUTH'] = 'server|timestamp|nonce';

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid X-Mata-Auth header format', $result['error']);
    }

    public function testInvalidTimestampFormatFails(): void
    {
        $_SERVER['HTTP_X_MATA_AUTH'] = 'dashboard|invalid-timestamp|' . bin2hex(random_bytes(16)) . '|signature';

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid timestamp format', $result['error']);
    }

    public function testExpiredTimestampFails(): void
    {
        // Timestamp 200 seconds in the past (beyond 120 second window)
        $expiredTimestamp = time() - 200;
        $authHeader = $this->generateValidAuthHeader($expiredTimestamp);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Timestamp outside allowed window', $result['error']);
    }

    public function testFutureTimestampFails(): void
    {
        // Timestamp 200 seconds in the future (beyond 120 second window)
        $futureTimestamp = time() + 200;
        $authHeader = $this->generateValidAuthHeader($futureTimestamp);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Timestamp outside allowed window', $result['error']);
    }

    public function testTimestampWithinClockSkewSucceeds(): void
    {
        // Timestamp 100 seconds in the past (within 120 second window)
        $validTimestamp = time() - 100;
        $authHeader = $this->generateValidAuthHeader($validTimestamp);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertTrue($result['success']);
    }

    public function testInvalidServerIdFails(): void
    {
        $authHeader = $this->generateValidAuthHeader(serverId: 'invalid-server');
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid server ID', $result['error']);
    }

    public function testNonceTooShortFails(): void
    {
        $shortNonce = str_repeat('a', 15); // 15 chars, minimum is 16
        $authHeader = $this->generateValidAuthHeader(nonce: $shortNonce);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Nonce length must be between', $result['error']);
    }

    public function testNonceTooLongFails(): void
    {
        $longNonce = str_repeat('a', 65); // 65 chars, maximum is 64
        $authHeader = $this->generateValidAuthHeader(nonce: $longNonce);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Nonce length must be between', $result['error']);
    }

    public function testReplayAttackWithSameNonceFails(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $authHeader = $this->generateValidAuthHeader(nonce: $nonce);

        // First request should succeed
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;
        $result1 = $this->auth->validateHmacAuth();
        $this->assertTrue($result1['success']);

        // Second request with same nonce should fail
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;
        $result2 = $this->auth->validateHmacAuth();
        $this->assertFalse($result2['success']);
        $this->assertEquals('Nonce already used (replay attack detected)', $result2['error']);
    }

    public function testInvalidSignatureFails(): void
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $wrongApiKey = 'wrong-api-key';

        // Generate header with wrong API key
        $authHeader = $this->generateValidAuthHeader($timestamp, $nonce, null, $wrongApiKey);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid HMAC signature', $result['error']);
    }

    public function testTamperedPayloadFails(): void
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $payload = $this->testServerId . '|' . $timestamp . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, $this->testApiKey);

        // Tamper with the timestamp in the header
        $tamperedHeader = $this->testServerId . '|' . ($timestamp + 10) . '|' . $nonce . '|' . $signature;
        $_SERVER['HTTP_X_MATA_AUTH'] = $tamperedHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid HMAC signature', $result['error']);
    }

    public function testNonceCacheSizeTracking(): void
    {
        $this->assertEquals(0, $this->auth->getNonceCacheSize());

        // Add first nonce
        $authHeader1 = $this->generateValidAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader1;
        $this->auth->validateHmacAuth();
        $this->assertEquals(1, $this->auth->getNonceCacheSize());

        // Add second nonce
        $authHeader2 = $this->generateValidAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader2;
        $this->auth->validateHmacAuth();
        $this->assertEquals(2, $this->auth->getNonceCacheSize());

        // Clear cache
        $this->auth->clearNonceCache();
        $this->assertEquals(0, $this->auth->getNonceCacheSize());
    }

    public function testMultipleValidRequestsWithDifferentNoncesSucceed(): void
    {
        // First request
        $authHeader1 = $this->generateValidAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader1;
        $result1 = $this->auth->validateHmacAuth();
        $this->assertTrue($result1['success']);

        // Second request with different nonce
        $authHeader2 = $this->generateValidAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader2;
        $result2 = $this->auth->validateHmacAuth();
        $this->assertTrue($result2['success']);

        // Third request with different nonce
        $authHeader3 = $this->generateValidAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader3;
        $result3 = $this->auth->validateHmacAuth();
        $this->assertTrue($result3['success']);

        // Cache should have 3 nonces
        $this->assertEquals(3, $this->auth->getNonceCacheSize());
    }

    public function testEdgeCaseMinimumNonceLength(): void
    {
        $minNonce = str_repeat('a', 16); // Exactly 16 chars
        $authHeader = $this->generateValidAuthHeader(nonce: $minNonce);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertTrue($result['success']);
    }

    public function testEdgeCaseMaximumNonceLength(): void
    {
        $maxNonce = str_repeat('a', 64); // Exactly 64 chars
        $authHeader = $this->generateValidAuthHeader(nonce: $maxNonce);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertTrue($result['success']);
    }

    public function testTimestampJustInsideClockSkewBoundarySucceeds(): void
    {
        // Stay one second inside the configured skew window to avoid clock tick flakiness.
        $boundaryTimestamp = time() - 119;
        $authHeader = $this->generateValidAuthHeader($boundaryTimestamp);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->auth->validateHmacAuth();

        $this->assertTrue($result['success']);
    }

    public function testEmptyHeaderPartsFail(): void
    {
        $_SERVER['HTTP_X_MATA_AUTH'] = '|||';

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        // Should fail on timestamp validation
        $this->assertEquals('Invalid timestamp format', $result['error']);
    }

    public function testHeaderWithExtraPartsFails(): void
    {
        $_SERVER['HTTP_X_MATA_AUTH'] = 'server|123456|nonce|signature|extra';

        $result = $this->auth->validateHmacAuth();

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid X-Mata-Auth header format', $result['error']);
    }
}
