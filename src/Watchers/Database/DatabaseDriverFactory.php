<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

use InvalidArgumentException;

final class DatabaseDriverFactory
{
    public const DEFAULT_ENGINE = 'mariadb';

    /** @var list<string> */
    public const ENGINES = ['mariadb'];

    public static function supports(string $engine): bool
    {
        return in_array($engine, self::ENGINES, true);
    }

    public function create(string $engine): DatabaseDriverInterface
    {
        return match ($engine) {
            'mariadb' => new MariaDbDriver(),
            default => throw new InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }
}
