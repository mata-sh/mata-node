<?php

declare(strict_types=1);

namespace Mata\Utils;

class Tools
{
    public function isTruthy(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['true', 'on', '1', 'yes'], true);
        }

        return false;
    }

    public function normalizePath(string $path, string $rootPath): string
    {
        if ($path === '') {
            return $path;
        }

        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        return rtrim($rootPath, '/') . '/' . ltrim($path, '/');
    }
}
