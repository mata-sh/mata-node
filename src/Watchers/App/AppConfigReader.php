<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

use Mata\Utils\Tools;

final class AppConfigReader
{
    /** @var array<string, array<string, string>> */
    private array $appsConfig = [];

    public function __construct(
        private readonly Tools $tools,
        private readonly string $rootPath,
        private readonly array $watcherConfig,
        private readonly array $transmitConfig,
    ) {}

    public function load(string $app): array
    {
        if (isset($this->appsConfig[$app])) {
            return $this->appsConfig[$app];
        }

        $result = [];
        $config = [];
        foreach ($this->watcherConfig[$app]['configs'] ?? [] as $cfg) {
            $filePath = $this->rootPath . DIRECTORY_SEPARATOR . $cfg;
            if (file_exists($filePath)) {
                $config = array_merge($config, $this->tools->loadEnv($filePath));
            }
        }

        foreach ($this->transmitConfig['transmit_config'] ?? [] as $transmit) {
            $result[$transmit] = $config[$transmit] ?? '';
        }

        $this->appsConfig[$app] = $result;

        return $result;
    }
}
