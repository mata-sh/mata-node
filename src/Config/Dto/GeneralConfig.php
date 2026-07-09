<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing general application configuration.
 *
 * Contains application-wide settings including maintenance mode,
 * IP whitelisting, access control settings, and monitoring flags.
 */
final class GeneralConfig
{
    /**
     * @param array<int,string> $allowedIps     List of IP addresses/CIDRs allowed to access protected endpoints
     * @param array<int,string> $trustedProxies List of proxy IP addresses/CIDRs trusted to supply X-Forwarded-For
     */
    public function __construct(
        public readonly bool $maintenanceModeEnabled,
        public readonly bool $ipWhitelistEnabled,
        public readonly array $allowedIps,
        public readonly array $trustedProxies,
    ) {}
}
