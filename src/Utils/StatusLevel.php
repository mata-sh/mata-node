<?php

declare(strict_types=1);

namespace Mata\Utils;

/**
 * Outcome of a check, ordered by severity. The value is the bin/mata-node exit code, as in
 * the dashboard's bin/mata, and the label the `status` of its JSON output.
 */
enum StatusLevel: int
{
    case Ok = 0;
    case Warning = 1;
    case Error = 2;

    public function label(): string
    {
        return strtolower($this->name);
    }

    public function worst(self $other): self
    {
        return $this->value >= $other->value ? $this : $other;
    }
}
