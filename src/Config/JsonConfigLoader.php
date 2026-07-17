<?php

declare(strict_types=1);

namespace Mata\Config;

use JsonException;

final class JsonConfigLoader
{
    /**
     * @return array<mixed>
     *
     * @throws StartupConfigException
     */
    public function loadArray(string $path): array
    {
        if (!file_exists($path)) {
            throw StartupConfigException::missing("Required configuration file is missing: {$path}", [basename($path)]);
        }

        if (!is_file($path) || !is_readable($path)) {
            throw StartupConfigException::invalid("JSON configuration file is unreadable: {$path}");
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw StartupConfigException::invalid("JSON configuration file is unreadable: {$path}");
        }

        try {
            $config = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw StartupConfigException::invalid("JSON configuration file is invalid: {$path}", previous: $e);
        }

        if (!is_array($config)) {
            throw StartupConfigException::invalid("JSON configuration file must contain an array: {$path}");
        }

        return $config;
    }
}
