<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

/**
 * The framework an app runs and, when it could be read from the installed files, its version.
 *
 * A declared framework whose files cannot be read is still reported, with a `null` version.
 */
final readonly class FrameworkInstallation
{
    public function __construct(
        public Framework $framework,
        public ?string $version,
    ) {}

    /**
     * @return array{name: string, version: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->framework->value, 'version' => $this->version];
    }
}
