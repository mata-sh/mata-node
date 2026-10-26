# Configuration reference

The node reads four files from `config/`. Create any missing ones from `config/example/`:

```bash
make bootstrap-config
```

| File | Contents |
| --- | --- |
| [`config.env`](#configconfigenv) | Dashboard credentials, access control, logging, rate limiting |
| [`apps.json`](#configappsjson) | Watched applications: logs, sessions, Composer, framework, monitoring switches |
| [`databases.json`](#configdatabasesjson) | Database connections for `/databases/*` |
| [`transmit.json`](#configtransmitjson) | Node limits, disk check, cron heartbeats |

`config/log-formats/` is optional: one YAML file per log format the built-in parsers do not
cover. See [Log formats](log-parsers.md).

- JSON files must be strict JSON: no comments, no trailing commas.
- After editing, run `make mata ARGS="config validate"`. It reports every problem the node would
  fail on or skip; see [Command line](cli.md).
- In Docker, env files are read only when the container is created: run `make dev-recreate`
  after editing them. Other setups pick up changes immediately.

**When startup fails**, every route returns `503` with `Retry-After: 300` and
`Cache-Control: no-store`:

```json
{"status": "not_ready", "reason": "configuration_missing", "missing_config_files": ["apps.json"]}
```

`reason` is `configuration_missing`, `configuration_invalid` or `initialization_failed`. File
names are basenames only.

## `config/config.env`

| Key | Default | Purpose |
| --- | --- | --- |
| `DASHBOARD_SERVER_ID` | `dashboard` | This node's key in the dashboard's `servers.json` and `servers.secrets.json`. |
| `DASHBOARD_API_KEY` | empty | Shared HMAC secret. Must match this node's `api_key` in the dashboard's `servers.secrets.json` (`servers.json` up to dashboard 0.8.0). |
| `DASHBOARD_CLOCK_SKEW_WINDOW` | `120` | Accepted request timestamp skew, in seconds. |
| `MAINTENANCE_MODE_ENABLED` | `OFF` | `ON` returns `503` with `Retry-After: 300` on every route except `/health`. |
| `IP_WHITELIST_ENABLED`, `ALLOWED_IPS` | `OFF`, empty | Restrict authenticated routes to IPs/CIDRs, e.g. `198.51.100.10,2001:db8::/32`. |
| `TRUSTED_PROXIES` | empty | The client IP is `REMOTE_ADDR`. `X-Forwarded-For` is used only when `REMOTE_ADDR` is listed here, so never list untrusted proxies. |
| `MONOLOG_LEVEL`, `APP_LOG_PATH` | `debug`, `logs/app.log` | The node's own log. |
| `TEMP_DIR` | `temp/` | Cache directory. |
| `COMPOSER_BIN`, `COMPOSER_PHP_BIN` | `composer` from `PATH` | Default Composer binaries for all apps. |
| `RATE_LIMIT_ENABLED`, `RATE_LIMIT_STORAGE` | `ON`, `file` | Storage is `file`, `redis` (set `RATE_LIMIT_REDIS_*`) or `apcu`. The Docker images include the apcu extension but not redis. |
| `RATE_LIMIT_API_MAX_REQUESTS`, `RATE_LIMIT_API_WINDOW_SECONDS` | `200`, `60` | Requests per client IP on authenticated routes. |
| `RATE_LIMIT_AUTH_FAILED_MAX_REQUESTS`, `RATE_LIMIT_AUTH_FAILED_WINDOW_SECONDS` | `10`, `300` | Failed logins per client IP. Once reached, the IP gets `429` on authenticated routes, even with valid credentials, until the window passes. |

Invalid values fail startup with `configuration_invalid`:

- `*_ENABLED`: `ON`/`OFF`, `TRUE`/`FALSE`, `YES`/`NO` or `1`/`0`, in any case.
- Numbers: whole numbers. Limits, windows and the clock skew window are at least `1`; the Redis
  port is `1`–`65535`.
- `RATE_LIMIT_STORAGE`: `redis` and `apcu` need their PHP extension when rate limiting is on.
- `MONOLOG_LEVEL`: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert` or
  `emergency`.

## `config/apps.json`

- Use lowercase app ids.
- The dashboard treats app ids as global across all nodes. Make them unique by adding a 
server suffix, e.g. `shop-eu` and `shop-us`.
- Relative paths resolve against the **parent directory of the node repo**, not `config/`. With
  the node at `/srv/mata-node`, `example-app/logs/error.log` is
  `/srv/example-app/logs/error.log`. `logs`, `readme`, `composer.*` and `sessions.path` also take
  absolute paths.

Minimal example:

```json
{
  "minimal-app": {
    "title": "Minimal Application",
    "url": "https://example.com/minimal",
    "path": "minimal-app",
    "env": "test",
    "logs": ["minimal-app/logs/error.log"]
  }
}
```

Full example:

```json
{
  "example-app": {
    "title": "Example Application",
    "url": "https://example.com/app",
    "path": "example-app",
    "env": "prod",
    "description": "Example app",
    "disabled": false,
    "framework": "laravel",
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
      {"type": "php_error_log", "paths": ["example-app/logs/error.log"]},
      {"type": "monolog", "paths": ["example-app/logs/app.log", "example-app/logs/debug.log"]},
      {"type": "json", "paths": ["example-app/logs/structured.log"]}
    ],
    "composer": {
      "json": "example-app/composer.json",
      "lock": "example-app/composer.lock",
      "bin": "example-app/composer.phar"
    }
  }
}
```

### App fields

| Key | Required | Default | Purpose |
| --- | --- | --- | --- |
| `title` | for `/apps` | – | Display name. |
| `url` | for `/apps` | – | Public URL. |
| `path` | for `/apps`, `/readme`, Composer auto-detect | – | App base path. |
| `env` | for `/apps` | – | Exactly `dev`, `test`, `stg` or `prod`. |
| `logs` | for a clean `/apps` | `[]` | See [Logs](#logs). |
| `description` | No | `""` | Returned by `/apps`. |
| `disabled` | No | `false` | `true` (or `"true"`) hides the app and its routes. |
| `status_endpoint` | No | – | Absolute `http`/`https` URL; see below. |
| `readme` | No | `<path>/README.md` | Served by `/{app}/readme`. |
| `monitoring` | No | all on | See [Monitoring](#monitoring). |
| `sessions` | No | unconfigured | See [Sessions](#sessions). |
| `framework` | No | detected | Framework or CMS reported by `/apps`: a known identifier, any other name, or `none` to report none. See [Framework](#framework). |
| `composer` | No | off | See [Composer](#composer). |

**Status endpoint.** The dashboard sends an unauthenticated `GET` to
`status_endpoint` and expects `{"maintenance_mode": false}` (or `true`). `/apps` returns it as
`status_url`, or `null` when unset. Without it, the dashboard probes `url` instead and cannot
detect maintenance mode.

### Monitoring

`monitoring` has seven switches, all `true` by default. List only the ones you turn off:

```json
"monitoring": {"loganalysis": false, "sessions": false}
```

| Setting | Applies to | When `false` |
| --- | --- | --- |
| `loganalysis` | node | No log parsing. `/apps` omits `status`; `/{app}/logs` returns `409`. |
| `logsize` | node | No log globbing or sizing. `/apps` omits `logs`; `/{app}/logs-status` returns `409`. |
| `sessions` | node | No session directory scan or Redis `SCAN`. `/apps` omits `session`; `/{app}/sessions` returns `409`. |
| `reachability` | dashboard | Stops probing the app. |
| `alarms` | dashboard | Records events but never raises alarms. |
| `events` | dashboard | Produces no events for the app. |
| `libraryaudit` | dashboard | Stops its scheduled Composer audit and library security events. `/{app}/composer-audit` stays available. |

A `409` body names the setting:

```json
{"error": "Monitoring disabled", "message": "Monitoring disabled", "details": {"app": "archived-shop", "setting": "logsize"}}
```

`/{app}/readme`, `/{app}/libraries` and `/{app}/composer-audit` are never blocked.

**Validation.** Values must be JSON `true` or `false`. Anything else (`"off"`, `0`, `null`, …),
unknown keys and a non-object `monitoring` make the app config invalid. Unknown keys are rejected
so a typo like `"logsizes": false` cannot silently leave a scan running. An invalid config makes
`/apps` and the app's blockable routes return `500` with the generic configuration error. Only
the field name (`monitoring.<key>`) and app id are logged, never the value.

### Logs

`logs` is either a list of paths, with the parser auto-detected per file:

```json
"logs": ["minimal-app/logs/error.log", "minimal-app/logs/app.log"]
```

or a list of groups:

```json
"logs": [
  {"type": "monolog", "paths": ["example-app/logs/app.log"]},
  {"paths": ["example-app/logs/auto-detect.log"]}
]
```

| Key | Required | Default | Purpose |
| --- | --- | --- | --- |
| `paths` | Yes | – | Log file paths. Groups without paths are skipped. |
| `type` | No | auto-detect | `php_error_log`, `monolog`, `json`, a format in `resources/log-formats/` (e.g. `nginx_error`) or one in `config/log-formats/`. See [Log formats](log-parsers.md). |

- **Auto-detection** samples a file's first 10 lines and picks the parser that matches at least
  half of them, falling back to `php_error_log`. An unknown `type` logs a warning and
  auto-detects. YAML-defined formats are never auto-detected.
- **Directories:** `/apps` log sizes and `/{app}/logs-status` include the `*.log` files inside a
  configured directory; `/{app}/logs` skips directories.
- **Memory:** for `/apps`, each file is streamed once, holding at most one line in memory (up to
  5 MB; longer lines are streamed through).
- To see which parser a log gets and what it parses, run `make mata ARGS="log test <app>"`.

### Sessions

`sessions.type` is `file` (the default when `path` is set), `redis` or `none`. File example: see
[the full example](#configappsjson). Redis example:

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

| Key | Backend | Default | Purpose |
| --- | --- | --- | --- |
| `path` | file | empty | Directory to scan. Missing, empty or not a directory gives counts of `0`. |
| `filename_regex` | file | all files | `preg_match()` pattern with delimiters, e.g. `"/^sess_/"`. |
| `dsn` | redis | – | Redis DSN. Required unless `dsn_env` is set. |
| `dsn_env` | redis | – | Environment variable holding the DSN. Prefer it when the DSN has credentials. |
| `prefix` | redis | `PHPREDIS_SESSION:` | Session key prefix to scan for. |
| `count_key` | redis | empty | Key holding the session count; see below. |
| `scan_count` | redis | `1000` | `SCAN` `COUNT` hint. |
| `timeout_seconds` | redis | client default | Socket timeout. |
| `cache_ttl_seconds` | redis | `60` | How long `SCAN` results are cached; `0` disables. |

**Counting Redis sessions.** Set `count_key` for large session stores: the node reads it with one
`GET` and never scans. The node never writes the key; your app must keep it a non-negative
integer. A missing key counts as `0`; a non-numeric value returns error metadata.

Without `count_key`, the node counts keys with `SCAN MATCH <prefix>*`. The count is approximate
(`SCAN` can return duplicates), so use it for monitoring, not exact accounting. Results are cached
in the node cache directory; the cache key contains no passwords or DSN credentials.

Redis reports only `count_total`; `count_last_hour`, `count_last_day`, `path` and `size_kb` are
`null`.

### Composer

Used by `/apps/libraries`, `/apps/composer-audit` and `/{app}/libraries`.

`"composer": true` (or `"true"`, `"on"`, `"1"`, `"yes"`) reads `<path>/composer.json` and
`<path>/composer.lock`. An object overrides individual settings:

```json
"composer": {
  "json": "example-app/composer.json",
  "lock": "example-app/composer.lock",
  "bin": "example-app/composer.phar"
}
```

| Key | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` if any other key is set | Truthy enables, falsy disables. |
| `json` | `<path>/composer.json` | Required for audits. |
| `lock` | `<path>/composer.lock` | Required for libraries and audits. |
| `bin` | `COMPOSER_BIN`, else `composer` from `PATH` | A `.phar` is run as `php <bin>`. |
| `php_bin` | `COMPOSER_PHP_BIN`, else `php` | PHP binary for running a `.phar`. |

### Framework

`/apps` reports `framework` as `{"name": "laravel", "version": "11.34.2"}` when a framework is
declared or detected.

| `framework` config | Result |
| --- | --- |
| absent, `null` or blank | Detect it from Composer packages and version files; omit `framework` if nothing matches. |
| `"none"` | Omit `framework`, without detection. |
| known identifier, such as `"laravel"` | Report that name, with the version read from its own files only, or `null`. |
| any other string | Report it as `name` with `version: null`, without detection. |
| anything else | Log a warning and omit `framework`. |

Values are trimmed and case-sensitive: `"Laravel"` is reported as a custom name without a
version. [Framework detection](frameworks.md) lists the known identifiers and the files they
are read from.

### App metadata responses

`/apps` returns, per app, these keys in this order:

```json
"example-app": {
  "path": "example-app",
  "title": "Example Application",
  "url": "https://example.com/app",
  "env": "prod",
  "status_url": "https://example.com/app/health/",
  "description": "Example app",
  "composer": true,
  "framework": { "name": "laravel", "version": "11.34.2" },
  "status": { "fatal": 7, "warnings": 12, "total": 4231, "fatal_cursor": "v1:98cc…9ab" },
  "session": { "type": "file", "status": "ok", "count_last_hour": 1, "count_last_day": 2, "count_total": 2, "path": "example-app/temp/sessions", "size_kb": 12.34 },
  "logs": { "example-app/logs/error.log": 128.5 }
}
```

- `framework` is present only when a framework was declared or detected; `version` may be
  `null`. Consumers must accept custom and future `name` values.
- `status`, `logs` and `session` are present only when `loganalysis`, `logsize` and `sessions`
  are on. A missing key means "not measured", not zero or clean.
- `monitoring` is present only when a switch is off, and lists only those switches (all `false`)
  in the order of the [Monitoring](#monitoring) table, e.g.
  `"monitoring": {"loganalysis": false, "sessions": false}`.
- `/{app}/sessions` returns the same `session` object.

**`status`.** `fatal`, `warnings` and `total` are summed over all the app's logs.
`fatal_cursor` is `null` when `fatal` is `0`, otherwise `v1:` plus 64 lowercase hex characters.

The cursor is an opaque fingerprint of the app's fatal entries, meant only for comparison: if it
differs from the last value, the fatal entries changed. It contains no log content, paths, file
identity, offsets or per-entry hashes; entries are in `/{app}/logs` and file details in
`/{app}/logs-status`.

- **Changes when** a fatal entry is added (even a duplicate), when a fatal entry's timestamp,
  message, stack trace, context, repeat count or order changes, or when log rotation replaces a
  file containing fatal entries. Multiline stack traces count; JSON records are canonicalized
  first, so key order does not matter.
- **Stays the same when** warnings, notices, info or other lines are appended, and across node
  restarts. The node is stateless, so rewriting a file with exactly the same fatal entries in the
  same positions looks unchanged.

## `config/databases.json`

Named connections to MariaDB, MySQL or PostgreSQL servers, used by the `/databases/*` routes.
`{}` turns database monitoring off; the routes then return `404`.

```json
{
  "connections": {
    "shop": {
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
    },
    "reporting": {
      "engine": "pgsql",
      "connection": {
        "host": "10.0.0.5",
        "port": 5432,
        "username": "mata_monitor",
        "password": "secret"
      },
      "databases": {
        "warehouse": "Data warehouse"
      }
    }
  }
}
```

| Field | Meaning |
| --- | --- |
| `connections` | Name => connection; at least one. |
| `engine` | `mariadb` (also for MySQL) or `pgsql`. |
| `connection` | `host`, `port` (string or number), `username`, `password`. Use a read-only account. |
| `databases` | Database name => description shown in the dashboard; at least one. |

- All fields are required. Unknown keys or missing fields fail startup with
  `configuration_invalid`. Startup never connects, so a database outage cannot stop the node.
- The connection name appears in the dashboard, the API and dashboard URLs. It may contain only
  letters, digits, `.`, `_` and `-`.
- Use one connection per login. The same database may appear under several connections.

**Responses.** `/databases/overview` returns one entry per connection with `connection`,
`engine`, `collected_at`, the server-wide `status` (connections, limits, uptime), `databases`
and `error`. A failing connection has `status: null` and `error: {"status_code": 503, ...}` and
its reason is logged as a warning; the other connections are still reported.
`/databases/processes?connection=NAME` lists only sessions on that connection's databases.

**Privileges.** The account needs no write rights. Without the privileges below,
`/databases/processes` reports `"visibility": "own_sessions"` and shows only the account's own
sessions.

- MariaDB/MySQL: `GRANT PROCESS ON *.* TO 'mata_monitor'@'...'`. `PROCESS` granted only through
  a role also counts as `own_sessions`.
- PostgreSQL: `GRANT pg_read_all_stats TO mata_monitor`, or superuser. Without it, the
  overview's `connections` and `running` also count only the account's own sessions. The node
  connects to the `postgres` database. `max_used_connections`, `refused_connections` and
  `aborted_connects` have no PostgreSQL equivalent and are `null`.

## `config/transmit.json`

One object; every key is optional.

```json
{
  "check_disk_space_enabled": "on",
  "check_disk_space_path": "/",
  "errorlog_readout_limit": 200,
  "errorlog_scan_line_limit": 20000,
  "system_filecount_cache_max_age": 1800,
  "cronjob_heartbeats": ["logs/cron/heartbeat-daily.log"],
  "cronjob_heartbeat_stale_seconds": 86400,
  "cronjob_heartbeat_tail_line_limit": 50
}
```

| Key | Default | Purpose |
| --- | --- | --- |
| `check_disk_space_enabled` | on | Only the exact string `"off"` disables disk checks; anything else, even `false`, enables them. |
| `check_disk_space_path` | `/` | Filesystem to check. Use an absolute path. Disk values are returned in KiB. |
| `errorlog_readout_limit` | `50` | Entries returned per file by `/{app}/logs` after filtering and grouping; repeats of one message count once. |
| `errorlog_scan_line_limit` | `20000` | Tail lines read per file before `/{app}/logs` parses and filters them. |
| `system_filecount_cache_max_age` | `1800` | Seconds a cached server file count is reused. |
| `cronjob_heartbeats` | `[]` | Heartbeat file paths, relative to the node root. A single string also works. |
| `cronjob_heartbeat_stale_seconds` | `86400` | Heartbeat files older than this are stale. |
| `cronjob_heartbeat_tail_line_limit` | `50` | Lines returned in `content_tail`. |

The two `cronjob_heartbeat_*` numbers fall back to the default when not numeric and are at
least `1`.

### Cron heartbeats

Cron runs per host, so heartbeats are configured per node, here rather than per app.
`/server/data` returns a summary:

```json
"cronjobs": {"total": 2, "working": 1, "stale": 0, "missing": 1}
```

`/server/cronjobs` returns one entry per file, in configured order:

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
