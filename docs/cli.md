# Command line

`bin/mata-node` checks the node's configuration as the node reads it. It changes nothing: it never
writes to `config/`, logs or the cache.

```bash
make mata ARGS="config validate"          # Docker: runs as www-data in the running container
sudo -u www-data php bin/mata-node config validate   # without Docker: as the web server user
```

Run it as the web server user (`www-data`; `http` on Arch, `apache` on RHEL). A log or app directory
your own user can read may still be unreadable to the node. It refuses to run as root; set
`MATA_ALLOW_ROOT=1` to override.

| Command | Checks |
| --- | --- |
| `config validate` | Every file in `config/`, reporting all problems instead of stopping at the first |
| `log test <app> [--limit N]` | Which parser each of the app's logs gets, what it parses, the `/apps` counts and the last `N` entries (default 5) |

`config:validate` and `log:test` work too. `bin/mata-node list` shows the commands and
`bin/mata-node help <command>` explains one; `list --format=json` describes them for scripts.

## Exit codes and JSON

Every command takes `--json` and prints one JSON document, failures included:

```json
{
  "schema_version": 1,
  "status": "warning",
  "config_path": "/var/www/html/config",
  "files": [
    { "file": "config.env", "status": "ok" },
    { "file": "apps.json", "status": "warning" },
    { "file": "databases.json", "status": "ok" },
    { "file": "transmit.json", "status": "ok" }
  ],
  "problems": [
    {
      "level": "warning",
      "file": "apps.json",
      "app": "shop",
      "field": "logs[0].type",
      "message": "Unknown log type \"laravel\"; the node auto-detects the format instead. Known: json, monolog, php_error_log"
    }
  ]
}
```

| Exit | `status` | Meaning |
| --- | --- | --- |
| `0` | `ok` | Nothing to fix |
| `1` | `warning` | The node skips a setting or falls back, e.g. a malformed log entry, an unknown log type, a missing log file, or a parser that finds no entries |
| `2` | `error` | The node answers `503`, or `/apps` fails with `500`, e.g. a missing file, invalid JSON, or an invalid app entry |

**make exits 2 for any failure.** `make mata` returns `2` whether the command warned or failed.
Read `status` from `--json`, or call `docker-compose exec -u www-data mata-web php bin/mata-node …`
for the real exit code.

## `config validate`

Uses the loaders the node runs at startup and for each request:

- **`config.env`:** values and required settings, as at startup.
- **`apps.json`:** each enabled app's metadata (`title`, `url`, `path`, `env`, `status_endpoint`,
  `monitoring`), its log entries and its session type. One invalid app makes `/apps` fail for all
  apps. Warns when the app's directory is unreadable, or `disabled` is not `true` or `false`.
- **`databases.json`:** the connection structure. Only the first problem is reported.
- **`transmit.json`:** only that it is a JSON object; every setting has a default.
- **`log-formats/`:** each format file, listed once the directory has one. A broken file only
  warns, as the node skips that format and auto-detects the logs of apps that name it.

A missing file is an error, and the command does not create it. `make bootstrap-config` does.

## `log test`

Use it after adding a log or setting its `type`. For each file it shows:

- the parser: the configured `type`, or the detected one
- how many entries that parser finds in the lines `/{app}/logs` scans (`errorlog_scan_line_limit`)
- the `fatal` and `warnings` counts `/apps` reports for the whole file
- the last `N` parsed entries

A parser that finds no entries in a non-empty file does not match the format: set the right `type`,
or [define the format](log-parsers.md). Files in a configured directory are listed but not parsed, as
by `/logs`. The command also works with `monitoring.loganalysis` switched off.
