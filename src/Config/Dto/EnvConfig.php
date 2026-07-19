<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing complete application configuration.
 *
 * Aggregates all configuration sections into a single cohesive object
 * that represents the entire application configuration state.
 */
final class EnvConfig
{
    public function __construct(
        public readonly EnvironmentConfig $environment,
        public readonly GeneralConfig $general,
        public readonly PhpConfig $php,
        public readonly LoggingConfig $logging,
        public readonly MailConfig $mail,
        public readonly RateLimitingConfig $rateLimiting,
        public readonly AuthConfig $auth,
        public readonly ComposerConfig $composer,
    ) {}
}
