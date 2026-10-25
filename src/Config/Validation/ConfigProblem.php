<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use JsonSerializable;
use Mata\Utils\StatusLevel;

/**
 * One finding of `config validate`. Errors fail startup or an app's routes; warnings are
 * settings the node ignores or replaces with a fallback.
 */
final readonly class ConfigProblem implements JsonSerializable
{
    public function __construct(
        public StatusLevel $level,
        public string $file,
        public string $message,
        public ?string $app = null,
        public ?string $field = null,
    ) {}

    public static function error(string $file, string $message, ?string $app = null, ?string $field = null): self
    {
        return new self(StatusLevel::Error, $file, $message, $app, $field);
    }

    public static function warning(string $file, string $message, ?string $app = null, ?string $field = null): self
    {
        return new self(StatusLevel::Warning, $file, $message, $app, $field);
    }

    /** Where the problem is, e.g. `apps.json shop.monitoring.logsize`. */
    public function location(): string
    {
        $key = "{$this->app}.{$this->field}";
        $key = trim($key, '.');

        return $key === '' ? $this->file : "{$this->file} {$key}";
    }

    /**
     * @return array{level: string, file: string, app: ?string, field: ?string, message: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'level' => $this->level->label(),
            'file' => $this->file,
            'app' => $this->app,
            'field' => $this->field,
            'message' => $this->message,
        ];
    }
}
