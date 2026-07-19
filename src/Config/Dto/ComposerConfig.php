<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing node-wide Composer audit command defaults.
 */
final class ComposerConfig
{
    public function __construct(
        public readonly ?string $bin,
        public readonly ?string $phpBin,
    ) {}
}
