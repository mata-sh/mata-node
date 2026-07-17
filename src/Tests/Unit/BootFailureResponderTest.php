<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\StartupFailureReason;
use Mata\Http\BootFailureResponder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Http\BootFailureResponder
 *
 * @internal
 */
final class BootFailureResponderTest extends TestCase
{
    private BootFailureResponder $responder;

    protected function setUp(): void
    {
        $this->responder = new BootFailureResponder();
    }

    public function testFailureHasStableJsonContract(): void
    {
        $response = $this->responder->build(StartupFailureReason::ConfigurationMissing, [
            '/private/config/apps.json',
            'mail.config.env',
            '../../config/apps.json',
        ]);

        $this->assertSame(503, $response->status);
        $this->assertSame('300', $response->headers['Retry-After']);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $this->assertSame('application/json', $response->headers['Content-Type']);
        $this->assertSame([
            'status' => 'not_ready',
            'reason' => 'configuration_missing',
            'missing_config_files' => ['mail.config.env', 'apps.json'],
        ], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMissingFilesAreSanitizedToKnownBasenames(): void
    {
        $response = $this->responder->build(StartupFailureReason::ConfigurationInvalid, [
            '/srv/private/config.env',
            '<script>alert(1)</script>',
        ]);

        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['config.env'], $body['missing_config_files']);
        $this->assertStringNotContainsString('/srv/private', $response->body);
        $this->assertStringNotContainsString('<script>', $response->body);
        $this->assertStringNotContainsString('alert(1)', $response->body);
    }

    public function testInitializationFailureDefaultsToEmptyFileList(): void
    {
        $response = $this->responder->build(StartupFailureReason::InitializationFailed);

        $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('initialization_failed', $body['reason']);
        $this->assertSame([], $body['missing_config_files']);
    }
}
