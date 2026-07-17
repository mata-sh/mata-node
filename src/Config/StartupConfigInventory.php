<?php

declare(strict_types=1);

namespace Mata\Config;

final class StartupConfigInventory
{
    /** @var list<string> */
    public const ENV_FILES = [
        'config.env',
        'mail.config.env',
    ];

    /** @var list<string> */
    public const JSON_FILES = [
        'apps.json',
        'databases.json',
        'transmit.json',
    ];

    /** @var list<string> */
    public const FILES = [...self::ENV_FILES, ...self::JSON_FILES];

    /**
     * @return list<string>
     */
    public static function findMissingFiles(string $configDir): array
    {
        return array_values(array_filter(
            self::FILES,
            static fn (string $file): bool => !file_exists($configDir . DIRECTORY_SEPARATOR . $file)
        ));
    }

    /**
     * @param array<array-key, mixed> $files
     *
     * @return list<string>
     */
    public static function safeBasenames(array $files): array
    {
        $basenames = [];

        foreach ($files as $file) {
            if (is_string($file)) {
                $basenames[basename($file)] = true;
            }
        }

        return array_values(array_filter(
            self::FILES,
            static fn (string $file): bool => isset($basenames[$file])
        ));
    }
}
