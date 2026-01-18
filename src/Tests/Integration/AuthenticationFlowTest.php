<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Config\Dto\AuthConfig;
use Mata\Core\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for HMAC authentication flow.
 *
 * Tests the complete authentication flow including header parsing,
 * validation, and error responses.
 *
 * @internal
 *
 * @coversNothing
 */
class AuthenticationFlowTest extends TestCase
{
    private Auth $auth;
    private string $testServerId = 'dashboard';
    private string $testApiKey = 'integration-test-api-key';

    protected function setUp(): void
    {
        $authConfig = new AuthConfig(
            dashboardServerId: $this->testServerId,
            dashboardApiKey: $this->testApiKey,
            clockSkewWindow: 120
        );
        $this->auth = new Auth($authConfig);
        $this->auth->clearNonceCache();

        // Clear server globals
        unset($_SERVER['HTTP_X_MATA_AUTH'], $_SERVER['AUTHENTICATED_SERVER_ID']);

    }

    protected function tearDown(): void
    {
        $this->auth->clearNonceCache();
        unset($_SERVER['HTTP_X_MATA_AUTH'], $_SERVER['AUTHENTICATED_SERVER_ID']);

    }

    private function generateAuthHeader(
        ?string $serverId = null,
        ?int $timestamp = null,
        ?string $nonce = null,
        ?string $apiKey = null
    ): string {
        $serverId = $serverId ?? $this->testServerId;
        $apiKey = $apiKey ?? $this->testApiKey;
        $timestamp = $timestamp ?? time();
        $nonce = $nonce ?? bin2hex(random_bytes(16));

        $payload = $serverId . '|' . $timestamp . '|' . $nonce;
        $signature = hash_hmac('sha256', $payload, $apiKey);

        return $serverId . '|' . $timestamp . '|' . $nonce . '|' . $signature;
    }

    private function simulateMiddleware(): array
    {
        $result = $this->auth->validateHmacAuth();

        if (!$result['success']) {
            return [
                'http_code' => 401,
                'response' => [
                    'error' => 'Unauthorized',
                    'message' => $result['error'],
                ],
            ];
        }

        // Simulate storing serverId in request
        $_SERVER['AUTHENTICATED_SERVER_ID'] = $result['serverId'];

        return [
            'http_code' => 200,
            'response' => ['success' => true],
        ];
    }

    public function testCompleteAuthenticationFlowSuccess(): void
    {
        // Simulate client generating auth header
        $authHeader = $this->generateAuthHeader();

        // Simulate request with header
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        // Process through middleware
        $middlewareResult = $this->simulateMiddleware();

        // Assert successful authentication
        $this->assertEquals(200, $middlewareResult['http_code']);
        $this->assertTrue($middlewareResult['response']['success']);
        $this->assertEquals($this->testServerId, $_SERVER['AUTHENTICATED_SERVER_ID']);
    }

    public function testCompleteAuthenticationFlowUnauthorized(): void
    {
        // Simulate request with invalid signature
        $authHeader = $this->generateAuthHeader(apiKey: 'wrong-key');
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        // Process through middleware
        $middlewareResult = $this->simulateMiddleware();

        // Assert unauthorized response
        $this->assertEquals(401, $middlewareResult['http_code']);
        $this->assertEquals('Unauthorized', $middlewareResult['response']['error']);
        $this->assertEquals('Invalid HMAC signature', $middlewareResult['response']['message']);
        $this->assertArrayNotHasKey('AUTHENTICATED_SERVER_ID', $_SERVER);
    }

    public function testSequentialRequestsWithUniqueNonces(): void
    {
        // Simulate 5 sequential requests from dashboard
        for ($i = 0; $i < 5; ++$i) {
            $authHeader = $this->generateAuthHeader();
            $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

            $result = $this->simulateMiddleware();

            $this->assertEquals(200, $result['http_code'], "Request {$i} failed");
            $this->assertEquals($this->testServerId, $_SERVER['AUTHENTICATED_SERVER_ID']);
        }

        // Verify nonce cache has 5 entries
        $this->assertEquals(5, $this->auth->getNonceCacheSize());
    }

    public function testReplayAttackDetectionInFlow(): void
    {
        // First request
        $authHeader = $this->generateAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;
        $result1 = $this->simulateMiddleware();
        $this->assertEquals(200, $result1['http_code']);

        // Replay the same request
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;
        $result2 = $this->simulateMiddleware();

        // Should be blocked
        $this->assertEquals(401, $result2['http_code']);
        $this->assertEquals('Nonce already used (replay attack detected)', $result2['response']['message']);
    }

    public function testConcurrentRequestsWithDifferentNonces(): void
    {
        // Simulate multiple concurrent requests (different nonces)
        $headers = [];
        for ($i = 0; $i < 10; ++$i) {
            $headers[] = $this->generateAuthHeader();
        }

        // Process all requests
        $results = [];
        foreach ($headers as $header) {
            $_SERVER['HTTP_X_MATA_AUTH'] = $header;
            $results[] = $this->simulateMiddleware();
        }

        // All should succeed
        foreach ($results as $i => $result) {
            $this->assertEquals(200, $result['http_code'], "Concurrent request {$i} failed");
        }

        // Nonce cache should have 10 entries
        $this->assertEquals(10, $this->auth->getNonceCacheSize());
    }

    public function testTimestampExpiredDuringLongRunningRequest(): void
    {
        // Simulate a timestamp that's 150 seconds old
        $oldTimestamp = time() - 150;
        $authHeader = $this->generateAuthHeader(timestamp: $oldTimestamp);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->simulateMiddleware();

        $this->assertEquals(401, $result['http_code']);
        $this->assertStringContainsString('Timestamp outside allowed window', $result['response']['message']);
    }

    public function testMultipleServerIdsRejected(): void
    {
        // Only 'dashboard' should be accepted
        $invalidServerIds = ['node', 'client', 'api-server', 'production'];

        foreach ($invalidServerIds as $serverId) {
            $authHeader = $this->generateAuthHeader(serverId: $serverId);
            $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

            $result = $this->simulateMiddleware();

            $this->assertEquals(401, $result['http_code'], "Server ID '{$serverId}' should be rejected");
            $this->assertEquals('Invalid server ID', $result['response']['message']);
        }
    }

    public function testHeaderCaseInsensitivityViaApacheHeaders(): void
    {
        // Some servers might provide headers differently
        // Test that we handle X-Mata-Auth correctly
        $authHeader = $this->generateAuthHeader();

        // Test with HTTP_X_MATA_AUTH (standard CGI format)
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;
        $result = $this->simulateMiddleware();
        $this->assertEquals(200, $result['http_code']);
    }

    public function testMalformedHeadersAreRejected(): void
    {
        $malformedHeaders = [
            '',
            'single-part',
            'two|parts',
            'three|parts|only',
            '||||',
            'serverId||nonce|signature', // missing timestamp
            '|timestamp|nonce|signature', // missing serverId
            'serverId|timestamp||signature', // missing nonce
            'serverId|timestamp|nonce|', // missing signature
        ];

        foreach ($malformedHeaders as $header) {
            $_SERVER['HTTP_X_MATA_AUTH'] = $header;
            $result = $this->simulateMiddleware();

            $this->assertEquals(401, $result['http_code'], "Malformed header '{$header}' should be rejected");
        }
    }

    public function testNonceCacheCleanupDoesNotAffectValidation(): void
    {
        // Add multiple nonces
        $headers = [];
        for ($i = 0; $i < 5; ++$i) {
            $header = $this->generateAuthHeader();
            $headers[] = $header;
            $_SERVER['HTTP_X_MATA_AUTH'] = $header;
            $result = $this->simulateMiddleware();
            $this->assertEquals(200, $result['http_code']);
        }

        // Cache should have 5 nonces
        $this->assertEquals(5, $this->auth->getNonceCacheSize());

        // New valid request should still work
        $newHeader = $this->generateAuthHeader();
        $_SERVER['HTTP_X_MATA_AUTH'] = $newHeader;
        $result = $this->simulateMiddleware();
        $this->assertEquals(200, $result['http_code']);

        // Cache should now have 6 nonces
        $this->assertEquals(6, $this->auth->getNonceCacheSize());
    }

    public function testAuthenticationWithMinimalValidNonce(): void
    {
        // Test with 16-character nonce (minimum)
        $minimalNonce = bin2hex(random_bytes(8)); // 16 hex chars
        $authHeader = $this->generateAuthHeader(nonce: $minimalNonce);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->simulateMiddleware();

        $this->assertEquals(200, $result['http_code']);
    }

    public function testAuthenticationWithMaximalValidNonce(): void
    {
        // Test with 64-character nonce (maximum)
        $maximalNonce = bin2hex(random_bytes(32)); // 64 hex chars
        $authHeader = $this->generateAuthHeader(nonce: $maximalNonce);
        $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

        $result = $this->simulateMiddleware();

        $this->assertEquals(200, $result['http_code']);
    }

    public function testBurstTrafficHandling(): void
    {
        // Simulate 50 requests in rapid succession
        $successCount = 0;
        $startTime = microtime(true);

        for ($i = 0; $i < 50; ++$i) {
            $authHeader = $this->generateAuthHeader();
            $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

            $result = $this->simulateMiddleware();
            if ($result['http_code'] === 200) {
                ++$successCount;
            }
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // All requests should succeed
        $this->assertEquals(50, $successCount);

        // Should complete reasonably fast (less than 1 second)
        $this->assertLessThan(1.0, $duration, 'Authentication should handle burst traffic efficiently');

        // Nonce cache should have 50 entries
        $this->assertEquals(50, $this->auth->getNonceCacheSize());
    }

    public function testClockSkewBoundaryConditions(): void
    {
        $testCases = [
            ['offset' => -120, 'shouldPass' => true, 'description' => 'Exactly 120s in past'],
            ['offset' => -121, 'shouldPass' => false, 'description' => 'Just over 120s in past'],
            ['offset' => 120, 'shouldPass' => true, 'description' => 'Exactly 120s in future'],
            ['offset' => 121, 'shouldPass' => false, 'description' => 'Just over 120s in future'],
            ['offset' => -60, 'shouldPass' => true, 'description' => '60s in past'],
            ['offset' => 60, 'shouldPass' => true, 'description' => '60s in future'],
            ['offset' => 0, 'shouldPass' => true, 'description' => 'Current timestamp'],
        ];

        foreach ($testCases as $testCase) {
            $timestamp = time() + $testCase['offset'];
            $authHeader = $this->generateAuthHeader(timestamp: $timestamp);
            $_SERVER['HTTP_X_MATA_AUTH'] = $authHeader;

            $result = $this->simulateMiddleware();

            if ($testCase['shouldPass']) {
                $this->assertEquals(200, $result['http_code'], $testCase['description'] . ' should pass');
            } else {
                $this->assertEquals(401, $result['http_code'], $testCase['description'] . ' should fail');
            }

            // Clear nonce cache between tests to avoid replay detection
            $this->auth->clearNonceCache();
        }
    }
}
