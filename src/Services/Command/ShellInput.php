<?php

declare(strict_types=1);

namespace Mata\Services\Command;

use InvalidArgumentException;

final class ShellInput
{
    private const DEFAULT_MAX_INT = 1_000_000;

    private function __construct() {}

    public static function positiveInt(int $value, int $min = 1, int $max = self::DEFAULT_MAX_INT): int
    {
        if ($min < 1 || $max < $min) {
            throw new InvalidArgumentException('invalid integer bounds');
        }

        return min($max, max($min, $value));
    }

    public static function path(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('shell path cannot be empty');
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('shell path cannot contain null bytes');
        }

        return $path;
    }

    public static function readableFile(string $path): string
    {
        $path = self::path($path);
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("file is not readable: {$path}");
        }

        return $path;
    }

    public static function safeProcessPattern(string $pattern): string
    {
        if ($pattern === '') {
            throw new InvalidArgumentException('process pattern cannot be empty');
        }

        if (!preg_match('/\A[A-Za-z0-9_.:@%+\-|]+\z/', $pattern)) {
            throw new InvalidArgumentException('process pattern contains unsupported characters');
        }

        return $pattern;
    }
}
