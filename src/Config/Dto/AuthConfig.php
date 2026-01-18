<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing authentication configuration.
 *
 * Contains HMAC-based authentication settings for validating
 * incoming requests from the dashboard server using the
 * X-Mata-Auth header.
 */
final class AuthConfig
{
    /**
     * @param string $dashboardServerId Expected server ID from the dashboard in X-Mata-Auth header
     * @param string $dashboardApiKey   API key used to validate HMAC signatures from the dashboard
     * @param int    $clockSkewWindow   Maximum allowed time difference in seconds (default: 120)
     */
    public function __construct(
        public readonly string $dashboardServerId,
        public readonly string $dashboardApiKey,
        public readonly int $clockSkewWindow = 120,
    ) {}
}
