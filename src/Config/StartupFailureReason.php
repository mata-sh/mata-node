<?php

declare(strict_types=1);

namespace Mata\Config;

/**
 * Why application startup failed; serialized as `reason` in the 503 response.
 */
enum StartupFailureReason: string
{
    case ConfigurationMissing = 'configuration_missing';
    case ConfigurationInvalid = 'configuration_invalid';
    case InitializationFailed = 'initialization_failed';
}
