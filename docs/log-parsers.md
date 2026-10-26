# Log formats

The node parses `php_error_log`, `monolog` and `json` logs with built-in parsers, and every format
defined in a YAML file. `type` in a log group of `apps.json` names the format:

```json
"logs": [{ "type": "plain_level", "paths": ["shop/logs/app.log"] }]
```

Without `type`, the node auto-detects `json`, `monolog` or `php_error_log` per file and falls back
to `php_error_log`. Formats defined in YAML are never auto-detected: they apply only where `type`
names them.

## Defining a format

Put one file per format in `config/log-formats/`, named after the format: `plain_level.yaml`
or `plain_level.yml` defines `plain_level`. Names use lowercase letters, digits and underscores. The directory is
optional, and in Docker it is part of the read-only `config/` mount.

A format with a pattern, for a log like `2026-10-09T12:00:00Z ERROR Payment failed`:

```yaml
# config/log-formats/plain_level.yaml
description: Plain lines with a level
pattern: '^(?<timestamp>\S+Z) (?<level>[A-Z]+) (?<message>.*)$'
levels:
  error: [ERROR, FATAL]
  warning: [WARN]
```

A format for one JSON record per line, such as `{"@timestamp": "…", "log.level": "error", "message": "…"}`:

```yaml
json:
  timestamp: '@timestamp'
  level: log.level
  message: message
levels:
  error: [error, critical]
  warning: [warning]
```

| Key | Required | Meaning |
| --- | --- | --- |
| `pattern` | `pattern` or `json` | Regular expression (PCRE) for the first line of an entry, without delimiters. Named groups `level` and `message` are required, `timestamp` is optional, and any other named group is passed through as a key of the entry. Write it in single quotes so backslashes stay as written; `(?i)` at the start makes it case-insensitive. |
| `json` | `pattern` or `json` | Fields of the record for `level`, `message` and, optionally, `timestamp`. A field is a key as written, else a dotted path into nested objects: `log.level` finds `{"log.level": …}` and `{"log": {"level": …}}`. |
| `levels` | Yes | The levels that are an `error` and those that are a `warning`. Compared without case; every other level is `Other`. |
| `description` | No | Free text for people reading the file. |

How the node reads a format:

- **Entries:** with a pattern, a line it matches starts an entry, and the lines up to the next match
  belong to its message, such as a stack trace. A JSON format reads each line as one entry and skips
  lines that are no JSON record.
- **Type and counts:** the level gives the entry's `type` (`Error`, `Warning` or `Other`), which
  `/{app}/logs` filters on, and the `fatal` and `warnings` counts of `/apps`. Those count entries
  whose first line has an error or warning level.
- **Timestamps:** most notations are read, such as `2026-10-26 08:00:00`, `2026/10/26 08:00:00`,
  ISO 8601 and `26/Oct/2026:08:00:00 +0000`, and returned as `Y-m-d H:i:s`. A timestamp the node
  cannot read is returned as `""`.
- **Limits:** a pattern sees only the first 8 KiB of a line, and a match that needs more than
  100,000 backtracking steps counts as no match, so a crafted log line cannot make the node slow.

`make mata ARGS="config validate"` reports a broken format file as a warning; the node does not
log it. The node skips that format: apps naming it get an auto-detected parser, as for any unknown
`type`. Check the
result on real logs with `make mata ARGS="log test shop"`. It shows the parser each file gets, the
entries it parses, and the fatal and warning counts (see [Command line](cli.md)).

A format file belongs to the instance, like `apps.json`: version it together with the rest of
`config/`. The node's `.gitignore` excludes `config/log-formats/`, so updating a checkout never
touches it.

## Grouping

`/{app}/logs` groups the parsed entries before applying the readout limit: entries of the same
`type` whose messages differ only in timestamps, IDs and numbers become one entry, the newest,
with `count`, `first_seen` and `last_seen`. A source location such as `Db.php:75` or `line 75`
is not masked, so the same message from another line stays separate. Counts and `first_seen`
cover only the scanned lines (`errorlog_scan_line_limit`), not the whole file.

```json
{"grouped": true, "readout_limit": 200, "entries": {"logs/error.log": [
  {"timestamp": "2026-10-25 22:48:49", "type": "Error", "message": "MySQL server has gone away in /src/Db.php:75",
   "count": 42, "first_seen": "2026-10-25 08:01:12", "last_seen": "2026-10-25 22:48:49"}]}}
```

## Adding a format to the node

A format that other installations than yours use too belongs in the node itself. Open a pull request
with:

- the format file in `resources/log-formats/`, written as for `config/log-formats/`
  (see `nginx_error.yaml`);
- a log fixture in `src/Tests/Fixtures/logs/`, with a test that parses it (see `DefinedLogParserTest`).

Shipped formats take their names first: a file in `config/log-formats/` with the same name is
skipped, so a name means the same format on every node.

The built-in parsers in `src/Watchers/LogParsers/` are PHP because they need more than a pattern,
such as auto-detection.
