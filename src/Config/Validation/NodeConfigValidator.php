<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Mata\Config\DatabasesConfigParser;
use Mata\Config\EnvConfigLoader;
use Mata\Config\JsonConfigLoader;
use Mata\Config\LogFormatLoader;
use Mata\Config\StartupConfigInventory;
use Mata\Watchers\LogParsers\LogParserFactory;
use Throwable;

/**
 * Runs the config files through the loaders the node uses at startup, without creating
 * missing files and without stopping at the first problem. `transmit.json` is only checked
 * for being a JSON object, as the node reads each of its settings with a default. The optional
 * log formats in `log-formats/` are listed as files once they exist.
 */
final class NodeConfigValidator
{
    public function __construct(
        private readonly EnvConfigLoader $envLoader = new EnvConfigLoader(),
        private readonly JsonConfigLoader $jsonLoader = new JsonConfigLoader(),
        private readonly DatabasesConfigParser $databasesParser = new DatabasesConfigParser(),
        private readonly AppConfigValidator $appValidator = new AppConfigValidator(),
        private readonly LogFormatLoader $formatLoader = new LogFormatLoader(),
    ) {}

    /**
     * @param string $rootPath Directory relative app paths resolve against
     */
    public function validate(string $configDir, string $rootPath): ConfigValidationReport
    {
        $problems = [];
        foreach (StartupConfigInventory::findMissingFiles($configDir) as $file) {
            $problems[] = ConfigProblem::error($file, "Missing: the node answers 503 configuration_missing until it exists. Start from config/example/{$file}.example");
        }

        $problems = [...$problems, ...$this->envProblems($configDir)];

        $formats = $this->formatLoader->load($configDir);
        $logTypes = (new LogParserFactory($formats['formats']))->names();

        foreach (StartupConfigInventory::JSON_FILES as $file) {
            $config = $this->loadJson($configDir, $file, $problems);
            if ($config !== null) {
                $problems = [...$problems, ...$this->contentProblems($file, $config, $rootPath, $logTypes)];
            }
        }

        return new ConfigValidationReport(
            $configDir,
            [...StartupConfigInventory::FILES, ...$formats['files']],
            [...$problems, ...$formats['problems']],
        );
    }

    /**
     * @return list<ConfigProblem>
     */
    private function envProblems(string $configDir): array
    {
        $present = array_values(array_filter(
            StartupConfigInventory::ENV_FILES,
            static fn (string $file): bool => file_exists($configDir . '/' . $file),
        ));
        if ($present === []) {
            return [];
        }

        $file = implode(', ', $present);

        try {
            $config = $this->envLoader->load($present, $configDir);
        } catch (Throwable $e) {
            return [ConfigProblem::error($file, $e->getMessage())];
        }

        return array_map(
            static fn (array $error): ConfigProblem => ConfigProblem::error($file, $error['message'], field: $error['identifier']),
            $this->envLoader->validateConfig($config),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $logTypes
     *
     * @return list<ConfigProblem>
     */
    private function contentProblems(string $file, array $config, string $rootPath, array $logTypes): array
    {
        if ($file === 'apps.json') {
            $problems = [];
            foreach ($config as $app => $appConfig) {
                $problems = [...$problems, ...$this->appValidator->validate((string) $app, $appConfig, $rootPath, $logTypes)];
            }

            return $problems;
        }

        if ($file === 'databases.json') {
            try {
                $this->databasesParser->parse($config);
            } catch (Throwable $e) {
                return [ConfigProblem::error($file, $e->getMessage())];
            }
        }

        return [];
    }

    /**
     * The parsed file, or null when it is missing (already reported) or invalid.
     *
     * @param list<ConfigProblem> $problems
     *
     * @return null|array<string, mixed>
     */
    private function loadJson(string $configDir, string $file, array &$problems): ?array
    {
        $path = $configDir . '/' . $file;
        if (!file_exists($path)) {
            return null;
        }

        try {
            return $this->jsonLoader->loadArray($path);
        } catch (Throwable $e) {
            $problems[] = ConfigProblem::error($file, $e->getMessage());

            return null;
        }
    }
}
