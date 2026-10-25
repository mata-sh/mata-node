<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use JsonSerializable;
use Mata\Utils\StatusLevel;

/**
 * Result of `config validate`: each config file's status and every problem found.
 */
final readonly class ConfigValidationReport implements JsonSerializable
{
    /**
     * @param list<string>        $files    Checked config files, in startup order
     * @param list<ConfigProblem> $problems
     */
    public function __construct(
        public string $configPath,
        public array $files,
        public array $problems,
    ) {}

    public function level(): StatusLevel
    {
        $level = StatusLevel::Ok;
        foreach ($this->problems as $problem) {
            $level = $level->worst($problem->level);
        }

        return $level;
    }

    public function fileLevel(string $file): StatusLevel
    {
        $level = StatusLevel::Ok;
        foreach ($this->problems as $problem) {
            if ($problem->file === $file) {
                $level = $level->worst($problem->level);
            }
        }

        return $level;
    }

    /**
     * @return array{status: string, config_path: string, files: list<array{file: string, status: string}>, problems: list<ConfigProblem>}
     */
    public function jsonSerialize(): array
    {
        $files = [];
        foreach ($this->files as $file) {
            $files[] = ['file' => $file, 'status' => $this->fileLevel($file)->label()];
        }

        return [
            'status' => $this->level()->label(),
            'config_path' => $this->configPath,
            'files' => $files,
            'problems' => $this->problems,
        ];
    }
}
