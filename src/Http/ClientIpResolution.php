<?php

declare(strict_types=1);

namespace Mata\Http;

final class ClientIpResolution
{
    public function __construct(
        public readonly string $clientIp,
        public readonly string $remoteAddr,
        public readonly ?string $forwardedFor,
        public readonly bool $usedTrustedProxy,
    ) {}
}
