<?php

declare(strict_types=1);

namespace Mata\Http;

use Mata\Config\StartupConfigInventory;
use Mata\Config\StartupFailureReason;

final class BootFailureResponder
{
    /**
     * @param array<array-key, mixed> $missingConfigFiles
     */
    public function build(StartupFailureReason $reason, array $missingConfigFiles = []): BootFailureResponse
    {
        return new BootFailureResponse(
            503,
            [
                'Retry-After' => '300',
                'Cache-Control' => 'no-store',
                'Content-Type' => 'application/json',
            ],
            json_encode([
                'status' => 'not_ready',
                'reason' => $reason->value,
                'missing_config_files' => StartupConfigInventory::safeBasenames($missingConfigFiles),
            ], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<array-key, mixed> $missingConfigFiles
     */
    public function respond(StartupFailureReason $reason, array $missingConfigFiles = []): never
    {
        $this->emit($this->build($reason, $missingConfigFiles));

        exit;
    }

    public function emit(BootFailureResponse $response): void
    {
        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $response->body;
    }
}
