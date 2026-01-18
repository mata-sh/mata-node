<?php

declare(strict_types=1);

namespace Mata\Utils;

use Exception;

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

    public function loadEnv($filePath)
    {
        $vars = [];
        if (!file_exists($filePath)) {
            throw new Exception('[ERROR] Environment file not found: ' . $filePath);
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue; // Skip comments
            }
            [$name, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strpos($value, ',') !== false) {
                // Split the value into an array if it contains commas
                $value = array_map('trim', explode(',', $value));
            }
            $vars[trim($name)] = $value;
        }

        return $vars;
    }
}
