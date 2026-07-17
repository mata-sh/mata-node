<?php

declare(strict_types=1);

namespace Mata\Http;

final readonly class BootFailureResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
