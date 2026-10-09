# Custom log parsers

The node ships parsers for `php_error_log`, `monolog` and `json`. To support another format, drop
a parser class into `src/Watchers/LogParsers/`. It is discovered automatically; no registration
needed.

## Requirements

- File name ends in `Parser.php`; class in namespace `Mata\Watchers\LogParsers`.
- Extends `AbstractLogParser`, which supplies the streaming `analyzeLog()` used for `/apps` counts
  and the fatal cursor.
- Constructible with no arguments (discovery instantiates each class once to read its name).
- `getName()` returns a unique name. That name is the `type` value in `apps.json`.

## Example

A format like `2026-10-09T12:00:00Z ERROR Payment failed`:

```php
<?php

declare(strict_types=1);

namespace Mata\Watchers\LogParsers;

use Mata\Services\Command\ShellCommands;

class PlainLevelParser extends AbstractLogParser
{
    private const LINE = '/^(\S+Z) (ERROR|WARN|INFO|DEBUG) (.*)$/';

    public function getName(): string
    {
        return 'plain_level';
    }

    // Auto-detection: claim the file if most of its first lines match.
    public function detect(string $logFile): bool
    {
        $head = $this->shell(ShellCommands::head($logFile, 10));
        if ($head === null || trim($head) === '') {
            return false;
        }

        $lines = explode("\n", trim($head));
        $matches = count(preg_grep(self::LINE, $lines));

        return $matches / count($lines) >= 0.5;
    }

    // Used by /{app}/logs on the tail of the file.
    public function parseLines(array $lines): array
    {
        $entries = [];
        foreach ($lines as $line) {
            if (!preg_match(self::LINE, $line, $m)) {
                continue;
            }
            $entries[] = [
                'timestamp' => $this->normalizeTimestamp($m[1]),
                'type' => match ($m[2]) {
                    'ERROR' => 'Error',
                    'WARN' => 'Warning',
                    default => 'Other',
                },
                'message' => $m[3],
            ];
        }

        return $entries;
    }

    protected function fatalLinePattern(): string
    {
        return '/^\S+Z ERROR /';
    }

    protected function warningLinePattern(): string
    {
        return '/^\S+Z WARN /';
    }

    protected function severityPatternsMatchLineStartOnly(): bool
    {
        return true; // both patterns are anchored with ^
    }
}
```

Use it in `apps.json`:

```json
"logs": [{ "type": "plain_level", "paths": ["shop/logs/app.log"] }]
```

## Contract

| Method | Used by | Must |
| --- | --- | --- |
| `parseLines()` | `/{app}/logs` | Return entries with `timestamp` (`Y-m-d H:i:s` or `""`), `type` (`Error`, `Warning` or `Other`; the `errors`/`warnings` filters match on it) and `message`. Extra keys are passed through. |
| `fatalLinePattern()`, `warningLinePattern()` | `/apps` `status` counts, fatal cursor | Match one physical line. |
| `entryStartPattern()` | fatal cursor | Optional. Match the first line of a multiline entry so stack traces belong to it. Default: every line is an entry. |
| `canonicalizeEntryLine()` | fatal cursor | Optional. Normalize single-line entries whose serialization varies (see `JsonParser`). |
| `detect()` | auto-detection | Return `true` only for files in this format. |

Parsers are tried in file name order during auto-detection and the first match wins; files no
parser claims fall back to `php_error_log`. Give a strict `detect()` so it cannot claim other
formats, or set `type` explicitly in `apps.json`.

Add a unit test next to the existing ones in `src/Tests/Unit/` (see `MonologParserTest`).
