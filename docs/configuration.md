# Configuration reference

MATA Node reads two JSON files:

- `config/apps.json` — static metadata and monitoring configuration for watched applications.
- `config/transmit.json` — node operational limits.

Bootstrap from the examples:

```bash
cp config/example/apps.json.example config/apps.json
cp config/example/transmit.json.example config/transmit.json
```

Do not keep trailing commas. The loader uses PHP `json_decode()` on strict JSON.

## Environment files

In Docker, changes to `config/config.env` or `config/mail.config.env` do not affect running containers because env files are only read at container creation. Run `make dev-recreate` to apply changes; `make dev-restart` is not enough. Non-Docker setups pick up changes immediately.

## Path base

For `apps.json`, most app paths are resolved relative to the parent directory of this node repo, not relative to `config/`.

Example: if the node is at `/srv/mata-node`, relative app paths are read from `/srv/...`.

Current path handling:

- `logs`, `sessions.path`, `readme`: relative paths are resolved against the parent directory. `logs` paths beginning with `/` are used as absolute paths.
- `composer.json`, `composer.lock`, `composer.bin`: relative paths are normalized against the same parent directory; absolute paths are accepted.
- `transmit.json.check_disk_space_path`: passed directly to PHP/disk commands. Use an absolute path.

## `config/apps.json`

Top-level structure: object map of `app-id` to app config object.

When used with MATA Dashboard, `app-id` is a global application identifier across all monitored nodes. Use a server-specific suffix for deployments that must remain separate applications.

Use lowercase `app-id` values. Route lookups lowercase the requested app name before matching it against the config key.

### Full example

```json
{
  "example-app": {
    "title": "Example Application",
    "url": "https://example.com/app",
    "path": "example-app",
    "disabled": "false",
    "env": "prod",
    "status_endpoint": "https://example.com/app/health/",
    "error_monitoring_enabled": true,
    "logsize_monitoring_enabled": true,
    "sessions": {
      "type": "file",
      "path": "example-app/temp/sessions",
      "filename_regex": "/^sess_/"
    },
    "logs": [
      {
        "type": "php_error_log",
        "paths": [
          "example-app/logs/error.log"
        ]
      },
      {
        "type": "monolog",
        "paths": [
          "example-app/logs/app.log",
          "example-app/logs/debug.log"
        ]
      },
      {
        "type": "json",
        "paths": [
          "example-app/logs/structured.log"
        ]
      }
    ],
    "description": "Example app",
    "composer": {
      "json": "example-app/composer.json",
      "lock": "example-app/composer.lock",
      "bin": "example-app/composer.phar"
    }
  }
}
```

### Minimal example

```json
{
  "minimal-app": {
    "title": "Minimal Application",
    "url": "https://example.com/minimal",
    "path": "minimal-app",
    "env": "test",
    "logs": [
      "minimal-app/logs/error.log"
    ]
  }
}
```

### App fields

| Key | Required | Default | Behavior |
| --- | --- | --- | --- |
| `title` | Yes for `/apps` | none | Returned as app title. |
| `url` | Yes for `/apps` | none | Returned as app URL. |
| `path` | Yes for `/apps`, `/readme`, composer auto-detect | none | App base path. Used for default README path and composer auto-detect. |
| `env` | Yes for `/apps` | none | Static application environment. Must be exactly `dev`, `test`, `stg`, or `prod`. |
| `disabled` | No | `"false"` | Set to `"true"` to hide the app and make its app-specific endpoints unavailable. |
| `status_endpoint` | No | none | Complete absolute `http` or `https` URL. The dashboard calls it directly; the node never calls it. |
| `ping` | No | `"on"` | Returned by `/apps`. No other node behavior found. |
| `sessions` | No | unconfigured | Session metadata config returned by `/apps` and `/{app}/sessions`. |
| `sessions.type` | No | `file` when `sessions.path` exists | Session backend type: `file`, `redis`, or `none`. |
| `sessions.path` | No | empty | Directory scanned for file sessions. Missing/empty/non-directory returns counts `0`. |
| `sessions.filename_regex` | No | empty | File backend only. Passed to `preg_match()` for each session filename. Empty means count all files. Include regex delimiters, e.g. `"/^sess_/"`. |
| `sessions.dsn` | Yes for Redis unless `dsn_env` is set | none | Redis DSN, e.g. `redis://127.0.0.1:6379/0`. |
| `sessions.dsn_env` | No | none | Environment variable name containing the Redis DSN. Prefer this when the DSN includes credentials. |
| `sessions.prefix` | No | `PHPREDIS_SESSION:` | Redis session key prefix used by SCAN fallback. |
| `sessions.count_key` | No | empty | Redis key containing the current session count. When set, the node uses one `GET` and does not scan. The app/session writer must maintain this key. |
| `sessions.scan_count` | No | `1000` | Redis SCAN count hint used only when `count_key` is empty. |
| `sessions.timeout_seconds` | No | client default | Redis socket timeout in seconds. |
| `sessions.cache_ttl_seconds` | No | `60` for Redis SCAN | Redis SCAN metadata cache TTL in seconds. `0` disables Redis session count caching. Ignored when `count_key` is set. |
| `logs` | Yes for clean `/apps`; optional for `/logs` and `/logs-status` | `[]` for `/logs` and `/logs-status` | Log paths/groups read for recent lines and status counts. See log formats below. |
| `description` | No | empty string | Returned by `/apps`. |
| `readme` | No | `<path>/README.md` | Path used by `/{app}/readme`. |
| `error_monitoring_enabled` | No | `true` | Literal JSON boolean. Returned by `/apps`. Controls dashboard fatal-error event production. |
| `logsize_monitoring_enabled` | No | `true` | Literal JSON boolean. Returned by `/apps`. Controls dashboard `logfile_too_large` production. |
| `composer` | No | disabled | Composer library/audit config. See composer fields below. |

### Monitoring settings

Both settings are optional literal JSON booleans. Omitting a setting means `true`. Only `true`
and `false` are accepted; string, numeric, `null`, array, and object values are an invalid
application configuration and make `/apps` fail with the generic configuration error. The
rejected value is never returned or logged, only the field name.

```json
{
  "shop": {
    "error_monitoring_enabled": true,
    "logsize_monitoring_enabled": false
  }
}
```

The two settings are independent: disabling fatal-error monitoring does not disable log-size
monitoring, and vice versa. Neither setting changes what the node measures — fatal counts, the
fatal cursor, log paths, and log sizes stay in the `/apps` response either way. The node only
reports the settings; the dashboard decides what to do with them, and a dashboard-side setting
may still suppress what the node reports as enabled.

The legacy `alarms_enabled` setting was removed in 2026-07. It never affected node behavior and
the dashboard only turned it into an unused CSS class, so it suppressed nothing. Use
`error_monitoring_enabled` to stop fatal-error alarms for an application. Leftover
`alarms_enabled` keys in an existing `apps.json` are ignored.

### App metadata responses

`/apps` returns nested session metadata instead of flat session fields:

```json
"session": {
  "type": "file",
  "count_last_hour": 1,
  "count_last_day": 2,
  "count_total": 2,
  "path": "example-app/temp/sessions",
  "size_kb": 12.34
}
```

`/{app}/sessions` returns the same object for one app.

Every app also returns both monitoring booleans and a log `status` object:

```json
"error_monitoring_enabled": true,
"logsize_monitoring_enabled": true,
"status": {
  "fatal": 7,
  "warnings": 12,
  "total": 4231,
  "fatal_cursor": "v1:98cc8c0d5d4c3a2b1e0f9876543210abcdef0123456789abcdef0123456789ab"
}
```

`fatal`, `warnings`, and `total` are non-negative integers aggregated over all configured logs of
the app. `fatal_cursor` is `null` exactly when `fatal` is `0`; otherwise it is `v1:` followed by 64
lowercase hexadecimal characters.

The cursor is opaque and equality-only. It does not identify, describe, or expose an error: it
carries no log content, no absolute path, no file identity, no offsets, and no per-entry hashes.
Comparing two cursors for the same app answers exactly one question — whether the fatal
observation changed. Detailed entries stay behind `/{app}/logs`; line counts, byte sizes, and
modification times stay behind `/{app}/logs-status`. `/apps` never calls either endpoint.

The cursor changes when a fatal entry is added (including a duplicate of an existing one), when a
fatal entry's timestamp, message, stack trace, context, multiplicity, or order changes, and when
log rotation replaces a fatal-bearing file with a new file generation. It does not change when
warnings, notices, info entries, or unrelated lines are appended, and it is stable across node
restarts. The node is stateless, so an in-place rewrite that reproduces exactly the same fatal
entries at the same positions in the same file is indistinguishable from an unchanged file.

`/apps` also returns static `env` and `status_url`. `status_url` is the validated `status_endpoint`, or `null` when no endpoint is configured. It never returns `status_endpoint`.

The dashboard, not MATA Node, makes an unauthenticated read-only `GET` to `status_url`. The endpoint must return:

```json
{"maintenance_mode": false}
```

Without a status endpoint, the dashboard uses the visitor `url` for its legacy availability probe and cannot determine dynamic maintenance state.

Redis session config:

```json
"sessions": {
  "type": "redis",
  "dsn": "redis://127.0.0.1:6379/0",
  "prefix": "PHPREDIS_SESSION:",
  "count_key": "mata:sessions:example-app:count",
  "scan_count": 1000,
  "timeout_seconds": 1.0,
  "cache_ttl_seconds": 60
}
```

For low-stress Redis counting, set `count_key`. The node reads `count_total` with one `GET` and never scans. Missing `count_key` values are reported as `0`; non-numeric values return error metadata. The node never mutates this key; the app/session writer is responsible for incrementing/decrementing or otherwise maintaining a non-negative integer value.

Operational rule: configure `count_key` for high-cardinality Redis session stores. Leave it empty only when SCAN fallback load is acceptable.

If `count_key` is not set, Redis returns `count_total` from a full `SCAN MATCH <prefix>*` pass. `SCAN` count is approximate: Redis may return duplicate keys during an iteration, and `COUNT` is only a per-call hint. The value is suitable for monitoring, not billing or exact accounting.

SCAN-based Redis session metadata is cached in the node cache directory for `cache_ttl_seconds` to avoid a full keyspace scan on every dashboard poll. Cache keys include the app identity and non-secret Redis identity fields (`host`, `port`, `database`, `prefix`, or sanitized DSN parts). Passwords and DSN credentials are not included. `count_last_hour`, `count_last_day`, `path`, and `size_kb` are `null`.

`dsn_env` is also supported to read the Redis DSN from an environment variable instead of config JSON.

### Log formats

`logs` supports both formats. Paths beginning with `/` are absolute; all other paths are resolved against the node's parent directory.

Old format: array of path strings. Parser type is auto-detected per file.

```json
{
  "logs": [
    "minimal-app/logs/error.log",
    "minimal-app/logs/app.log"
  ]
}
```

A configured path (in either format) may also be a directory. For `/apps` log-size output and `/logs-status`, the node expands directory paths into the `*.log` files they contain. `/logs` parses only explicitly configured file paths and skips directories.

New format: array of log groups:

```json
{
  "logs": [
    {
      "type": "monolog",
      "paths": [
        "example-app/logs/app.log"
      ]
    },
    {
      "paths": [
        "example-app/logs/auto-detect.log"
      ]
    }
  ]
}
```

Log group fields:

| Key | Required | Default | Behavior |
| --- | --- | --- | --- |
| `paths` | Yes for structured groups | none | Array of log file paths. Empty/missing groups are skipped. |
| `type` | No | auto-detect | Parser name. Supported values: `php_error_log`, `monolog`, `json`. |

If `type` is omitted, the node auto-detects the parser per file from the first 10 lines. It picks a parser when at least 50% of sampled lines match that parser. If none match, it falls back to `php_error_log`.

If `type` is set to an unknown value, the node logs an error and falls back to `php_error_log`.

App-level parser format settings are unsupported. Use structured log group `type` or omit `type` for auto-detection.

`/apps` log-size output supports old string paths and structured log group `paths`.

#### Analysis behavior

For `/apps` counts and the fatal cursor, each configured log file is read once as a bounded-memory
stream; the node never loads a whole log into memory and never shells out for counting. Severity
counting stays line based: `fatal` and `warnings` count matching physical lines, `total` counts
newline-terminated lines.

The fatal cursor additionally fingerprints the complete ordered fatal entries of each file.
`php_error_log` and `monolog` entries include their multiline stack traces and context blocks.
JSON records are canonicalized before hashing, so re-serializing the same record with a different
key order does not change the cursor. Per-file fingerprints are combined in canonical sorted order,
so the cursor does not depend on the order logs appear in the configuration or on map iteration
order.

Severity counting is line-complete at any line length, matching what `grep -c` would report. Peak
memory is one logical line, capped at 5 MB; a longer line is streamed past that point while
matching continues across the boundary, so its counts stay correct. Only the JSON key-order
canonicalization needs a whole record in memory, so it applies to records up to that 5 MB cap;
beyond it the record's raw bytes are fingerprinted instead.

### Composer fields

Composer config is used by:

- `/apps/libraries`
- `/apps/composer-audit`
- `/{app}/libraries`

Supported forms:

```json
{
  "composer": {
    "json": "example-app/composer.json",
    "lock": "example-app/composer.lock",
    "bin": "example-app/composer.phar"
  }
}
```

```json
{
  "composer": true
}
```

```json
{
  "composer": "on"
}
```

With `composer: true` or a truthy string (`true`, `on`, `1`, `yes`), the node auto-detects:

- `<path>/composer.json`
- `<path>/composer.lock`

Object fields:

| Key | Required | Default | Behavior |
| --- | --- | --- | --- |
| `composer.json` | Yes when using object form | none | Path to `composer.json`. Required for audit. |
| `composer.lock` | Yes when using object form | none | Path to `composer.lock`. Required for libraries and audit. |
| `composer.bin` | No | `composer` from `PATH` | Composer executable. If it ends in `.phar`, the command is run as `php <bin>`. |

## `config/transmit.json`

Top-level structure: one object.

### Example

```json
{
  "check_disk_space_enabled": "on",
  "check_disk_space_path": "/",
  "errorlog_readout_limit": 200,
  "errorlog_scan_line_limit": 20000,
  "system_filecount_cache_max_age": 1800,
  "cronjob_heartbeats": [
    "logs/cron/heartbeat-daily.log"
  ],
  "cronjob_heartbeat_stale_seconds": 86400,
  "cronjob_heartbeat_tail_line_limit": 50
}
```

### Fields

| Key | Required | Default | Units | Behavior |
| --- | --- | --- | --- | --- |
| `check_disk_space_enabled` | No | enabled | `"off"` disables | Only the exact string `"off"` disables disk checks. Any other value, including boolean `false`, enables them. |
| `check_disk_space_path` | No | `/` | filesystem path | Path passed to disk-space checks. Returned disk values are KiB. |
| `errorlog_readout_limit` | No | `50` | entries | Default number of parsed log entries returned by `/{app}/logs` after filtering. |
| `errorlog_scan_line_limit` | No | `20000` | lines | Number of raw tail lines read before parsing and filtering `/{app}/logs`. |
| `system_filecount_cache_max_age` | No | `1800` | seconds | Used by server file-count caching. Cached value is reused while its age is less than or equal to this value; older cache is recalculated. |
| `cronjob_heartbeats` | No | empty array | paths | Ordered array of heartbeat file paths relative to the node root. A single string is also accepted. `/server/data` returns summary counts; `/server/cronjobs` returns per-file metadata and tail content. |
| `cronjob_heartbeat_stale_seconds` | No | `86400` | seconds | Existing heartbeat files older than this are marked stale. Non-numeric values use the default; numeric values are clamped to at least `1`. |
| `cronjob_heartbeat_tail_line_limit` | No | `50` | lines | Number of heartbeat file tail lines returned in `/server/cronjobs` `content_tail`. Non-numeric values use the default; numeric values are clamped to at least `1`. |

### Cronjob heartbeat monitoring

Cronjob heartbeats are server-scoped because cron runs per host. Configure them in `config/transmit.json`.

`/server/data` returns compact cronjob summary data:

```json
"cronjobs": {
  "total": 2,
  "working": 1,
  "stale": 0,
  "missing": 1
}
```

`/server/cronjobs` returns ordered detail data:

```json
[
  {
    "index": 0,
    "path": "logs/cron/heartbeat-daily.log",
    "exists": true,
    "mtime": "2026-07-07 12:00:00",
    "age_seconds": 42,
    "size": 1234,
    "stale": false,
    "content_tail": ["last line"]
  }
]
```

