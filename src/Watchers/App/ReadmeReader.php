<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

use RuntimeException;

final class ReadmeReader
{
    public function __construct(private readonly string $rootPath) {}

    public function readFromConfig(array $config): string
    {
        $readmeFile = $config['readme'] ?? $config['path'] . '/README.md';

        return $this->read($this->rootPath . '/' . $readmeFile);
    }

    public function read(string $readmeFile): string
    {
        if (!is_readable($readmeFile)) {
            throw new RuntimeException("readme.md not found or unreadable: {$readmeFile}");
        }

        $content = file_get_contents($readmeFile);
        if ($content === false) {
            throw new RuntimeException("readme.md not readable: {$readmeFile}");
        }

        return $content;
    }
}
