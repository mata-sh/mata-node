<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

/**
 * Engine-neutral session row. Never carries query text, only its statement keyword.
 */
final class ProcessRow
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $user,
        public readonly ?string $host,
        public readonly ?string $database,
        public readonly ?string $command,
        public readonly int $timeSeconds,
        public readonly ?string $state,
        public readonly ?string $statement,
        public readonly bool $running,
    ) {}

    /**
     * Reduce SQL text to its leading statement keyword, skipping comments.
     */
    public static function statementKeyword(?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        $pattern = '~^(?:\s+|/\*.*?\*/|(?:--\s|#)[^\n]*(?:\n|$))*([a-z]+)~is';

        return preg_match($pattern, $sql, $matches) === 1 ? strtoupper($matches[1]) : null;
    }

    /**
     * @return array{id: int, user: ?string, host: ?string, database: ?string, command: ?string, time_seconds: int, state: ?string, statement: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user' => $this->user,
            'host' => $this->host,
            'database' => $this->database,
            'command' => $this->command,
            'time_seconds' => $this->timeSeconds,
            'state' => $this->state,
            'statement' => $this->statement,
        ];
    }
}
