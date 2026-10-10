# Configuration reference

All four files in `config/` must exist, or every endpoint returns `503` with
`configuration_missing`. Create the missing ones from `config/example/`:

```bash
make bootstrap-config
```

| File | Contents |
| --- | --- |
| [`config.env`](#configconfigenv) | Dashboard credentials, access control, logging, rate limiting |
| [`apps.json`](#configappsjson) | Watched applications: logs, sessions, Composer, monitoring switches |
| [`databases.json`](#configdatabasesjson) | Database server login and monitored databases for `/databases/*` |
| [`transmit.json`](#configtransmitjson) | Node limits, disk check, cron heartbeats |

JSON files must be strict JSON (no comments, no trailing commas).

`/health` reports `checks.config_files_present` and lists `missing_config_files`. If startup
fails, every route returns `503` (`Retry-After: 300`, `Cache-Control: no-store`) with
`{"status": "not_ready", "reason": "...", "missing_config_files": [...]}`; `reason` is
`configuration_missing`, `configuration_invalid` or `initialization_failed`, and file names are
basenames only.

In Docker, env files are read only at container creation: run `make dev-recreate` after editing
them. Non-Docker setups pick up changes immediately.

## `config/config.env`

| Key | Default | Purpose |
| --- | --- | --- |
| `DASHBOARD_SERVER_ID` | `dashboard` | This node's entry key in the dashboard's `servers.json`. |
| `DASHBOARD_API_KEY` | empty | Shared HMAC secret; the `api_key` of that entry. |
| `DASHBOARD_CLOCK_SKEW_WINDOW` | `120` | Accepted request timestamp skew, seconds. |
| `DOMAIN` | none (required) | Domain the node is served on. |
| `MAINTENANCE_MODE_ENABLED` | `OFF` | `ON` returns `503` with `Retry-After: 300` on every route except `/health`. |
| `IP_WHITELIST_ENABLED`, `ALLOWED_IPS` | `OFF`, empty | Restrict authenticated routes to listed IPs/CIDRs, e.g. `198.51.100.10,2001:db8::/32`. |
| `TRUSTED_PROXIES` | empty | Client IP is `REMOTE_ADDR`; `X-Forwarded-For` is used only when `REMOTE_ADDR` is listed here. Never list untrusted proxies. |
| `DISPLAY_ERRORS`, `LOG_LEVEL` | `0`, `notices` | PHP error display and level. |
| `MONOLOG_LEVEL`, `APP_LOG_PATH` | `debug`, `logs/app.log` | Node application log. |
| `TEMP_DIR` | `temp/` | Cache directory. |
| `COMPOSER_BIN`, `COMPOSER_PHP_BIN` | `composer` from `PATH` | Node-wide defaults for Composer audits. |
| `RATE_LIMIT_ENABLED`, `RATE_LIMIT_STORAGE` | `ON`, `file` | Rate limiting; storage `file` or `redis` (`RATE_LIMIT_REDIS_*`). |
| `RATE_LIMIT_API_MAX_REQUESTS`, `RATE_LIMIT_API_WINDOW_SECONDS` | `200`, `60` | Limit per client IP on authenticated routes. |
| `RATE_LIMIT_AUTH_FAILED_MAX_REQUESTS`, `RATE_LIMIT_AUTH_FAILED_WINDOW_SECONDS` | `10`, `300` | Limit on failed authentication attempts. |

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
    "monitoring": {
      "loganalysis": true,
      "logsize": true,
      "sessions": true,
      "reachability": true,
      "alarms": true,
      "events": true,
      "libraryaudit": true
    },
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
| `monitoring` | No | all settings enabled | JSON object of per-app monitoring switches. See monitoring settings below. |
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
| `composer` | No | disabled | Composer library/audit config. See composer fields below. |

### Monitoring settings

`monitoring` is an optional per-app JSON object with seven independent switches. Every switch
defaults to `true`, so an app without a `monitoring` block behaves exactly as it did before the
block existed.

```json
{
  "shop": {
    "monitoring": {
      "loganalysis": true,
      "logsize": true,
      "sessions": true,
      "reachability": true,
      "alarms": true,
      "events": true,
      "libraryaudit": true
    }
  }
}
```

The block may be sparse. Only list what you switch off:

```json
{
  "archived-shop": {
    "monitoring": {
      "loganalysis": false,
      "sessions": false
    }
  }
}
```

| Setting | Default | Effect when `false` |
| --- | --- | --- |
| `loganalysis` | `true` | The node performs no log parsing for the app. `/apps` omits `status`; `/{app}/logs` returns `409`. |
| `logsize` | `true` | The node performs no log file globbing or sizing. `/apps` omits `logs`; `/{app}/logs-status` returns `409`. |
| `sessions` | `true` | The node performs no session directory scan or Redis `SCAN`. `/apps` omits `session`; `/{app}/sessions` returns `409`. |
| `reachability` | `true` | No node behavior. Reported to the dashboard, which stops probing the app. |
| `alarms` | `true` | No node behavior. Reported to the dashboard, which records events but never elevates them into alarms. |
| `events` | `true` | No node behavior. Reported to the dashboard, which produces no events for the app. |
| `libraryaudit` | `true` | No node behavior. Reported to the dashboard, which stops its scheduled Composer audit and library security events for the app. `/{app}/composer-audit` stays available. Only relevant when `composer` is enabled. |

The first three actually skip work on the node — that is the point of the block. The last four
are transport-only: the node reports them and the dashboard acts on them.

#### Validation

| Input | Result |
| --- | --- |
| `monitoring` absent | all seven settings `true` |
| `monitoring` an empty object | all seven settings `true` |
| a key absent | that setting `true` |
| literal `true` / `false` | taken as-is |
| `"true"`, `"false"`, `"on"`, `"off"`, `""`, `1`, `0`, `1.0`, `null`, array, object | invalid application configuration, field `monitoring.<key>` |
| `monitoring` not a JSON object | invalid application configuration, field `monitoring` |
| unknown key inside `monitoring` | invalid application configuration, field `monitoring.<key>` |

Only literal JSON booleans are accepted. An invalid application configuration makes `/apps` and
the app's gated detail routes fail with the generic configuration error and HTTP `500`. The
rejected value is never returned or logged; only the field name is logged, alongside the app id.

Unknown keys are rejected deliberately. A typo such as `"logsizes": false` would otherwise
resolve silently to `true` and keep running the exact scan the operator meant to switch off.

#### Removed settings

The following per-app keys were removed. Leftover keys in an existing `apps.json` are ignored;
they are neither validated nor returned by `/apps`.

| Removed key | Removed | Replacement |
| --- | --- | --- |
| `alarms_enabled` | 2026-07 | `monitoring.alarms` |
| `ping` | 2026-07-25 | `monitoring.reachability` |
| `error_monitoring_enabled` | 2026-07-25 | `monitoring.loganalysis` |
| `logsize_monitoring_enabled` | 2026-07-25 | `monitoring.logsize` |

**Upgrading.** Migrate disabled settings by hand, e.g. `"ping": "off"` to
`"monitoring": {"reachability": false}`; otherwise the feature turns back on. Enabled settings
need no migration, since every `monitoring` switch defaults to `true`.

### App metadata responses

A fully monitored app returns these keys, in this order:

```json
"example-app": {
  "path": "example-app",
  "title": "Example Application",
  "url": "https://example.com/app",
  "env": "prod",
  "status_url": "https://example.com/app/health/",
  "description": "Example app",
  "composer": true,
  "status": { "fatal": 7, "warnings": 12, "total": 4231, "fatal_cursor": "v1:98cc…9ab" },
  "session": { "type": "file", "status": "ok", "count_last_hour": 1, "count_last_day": 2, "count_total": 2, "path": "example-app/temp/sessions", "size_kb": 12.34 },
  "logs": { "example-app/logs/error.log": 128.5 }
}
```

The response is shaped by the app's `monitoring` block:

- `status` is present iff `loganalysis` is enabled.
- `logs` is present iff `logsize` is enabled.
- `session` is present iff `sessions` is enabled.
- `monitoring` is present iff at least one setting is disabled, and then it lists **only** the
  disabled settings, always in the order `loganalysis`, `logsize`, `sessions`, `reachability`,
  `alarms`, `events`. There is never a `true` value inside it.

An app with `loganalysis` and `sessions` switched off therefore returns:

```json
"archived-shop": {
  "path": "archived-shop",
  "title": "Archived Shop",
  "url": "https://example.com/archived",
  "env": "prod",
  "status_url": null,
  "description": "",
  "composer": false,
  "logs": { "archived-shop/logs/error.log": 128.5 },
  "monitoring": { "loganalysis": false, "sessions": false }
}
```

An omitted key means "not measured", not "measured as zero". A consumer must not read an absent
`status` as a clean log or an absent `logs` as an empty log set.

`/{app}/sessions` returns the same `session` object for one app.

#### Gated detail routes

A disabled setting also closes the matching detail route, so a scan the operator switched off
cannot be triggered from the dashboard UI:

| Route | Requires | Refused with |
| --- | --- | --- |
| `/{app}/logs` | `loganalysis` | `409` |
| `/{app}/logs-status` | `logsize` | `409` |
| `/{app}/sessions` | `sessions` | `409` |

The refusal body names the setting:

```json
{
  "error": "Monitoring disabled",
  "message": "Monitoring disabled",
  "details": {"app": "archived-shop", "setting": "logsize"}
}
```

`/{app}/readme`, `/{app}/libraries`, and `/{app}/composer-audit` are not gated — they read no logs
and no sessions.

In `status`, `fatal`, `warnings`, and `total` are non-negative integers aggregated over all
configured logs of the app. `fatal_cursor` is `null` exactly when `fatal` is `0`; otherwise it is
`v1:` followed by 64 lowercase hexadecimal characters.

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

For `/apps` counts and the fatal cursor, each log file is streamed once with memory capped at one
line (max 5 MB; longer lines are streamed through). `fatal` and `warnings` count matching lines,
like `grep -c`; `total` counts newline-terminated lines. The cursor fingerprints the ordered fatal
entries including multiline stack traces; JSON records are canonicalized first, so key order does
not matter.

To support another log format, add a parser class: see [Custom log parsers](log-parsers.md).

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

With `composer: true` or a truthy string (`true`, `on`, `1`, `yes`), the node reads
`<path>/composer.json` and `<path>/composer.lock`.

Object fields (all optional):

| Key | Default | Behavior |
| --- | --- | --- |
| `composer.enabled` | `true` when any other key is set | Truthy value enables, falsy disables Composer for the app. |
| `composer.json` | `<path>/composer.json` | Path to `composer.json`. Required for audit. |
| `composer.lock` | `<path>/composer.lock` | Path to `composer.lock`. Required for libraries and audit. |
| `composer.bin` | `COMPOSER_BIN`, else `composer` from `PATH` | Composer executable. If it ends in `.phar`, the command is run as `php <bin>`. |
| `composer.php_bin` | `COMPOSER_PHP_BIN`, else `php` | PHP binary used to run a `.phar`. |

## `config/databases.json`

Lets the node watch one MariaDB, MySQL or PostgreSQL server for the `/databases/*` routes: which server,
how to log in, and which of its databases to report on.

```json
{
  "engine": "mariadb",
  "connection": {
    "host": "localhost",
    "port": "3306",
    "username": "mata_monitor",
    "password": "secret"
  },
  "databases": {
    "app_db": "Main application",
    "analytics_db": "Reporting"
  }
}
```

The node logs in once with `connection`. `/databases/overview` reports server-wide numbers
(connections, limits, uptime). `/databases/processes` lists only sessions on `app_db` and
`analytics_db`; sessions on any other database of that server are never returned.

`{}` turns database monitoring off; the `/databases/*` routes then return `404`.

| Field | Meaning |
| --- | --- |
| `engine` | Database type; selects the driver. `mariadb` (also for MySQL) or `pgsql` |
| `connection` | `host`, `port` (string or number), `username`, `password`; use a read-only account |
| `databases` | Database name => description shown in the dashboard; at least one |

All fields are required. Unknown keys and missing fields fail startup with
`configuration_invalid`. Startup never connects to the database, so an outage cannot stop the
node.

The account needs no write rights. For a complete process list it needs the `PROCESS`
privilege (`GRANT PROCESS ON *.* TO 'mata_monitor'@'...'`); without it `/databases/processes`
reports `"visibility": "own_sessions"` and shows only the account's own sessions. A privilege
granted only through a role is also reported as `own_sessions`.

On PostgreSQL the node connects to the `postgres` database. For a complete process list and
server-wide counts the account needs the `pg_read_all_stats` role
(`GRANT pg_read_all_stats TO mata_monitor`) or superuser; without it `/databases/processes`
reports `own_sessions`, and the overview's `connections` and `running` count only the account's
own sessions. PostgreSQL has no equivalent for `max_used_connections`, `refused_connections` and
`aborted_connects`; they are `null`.

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

