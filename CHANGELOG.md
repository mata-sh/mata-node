# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- The Docker images include the APCu extension, needed for `RATE_LIMIT_STORAGE=apcu`

### Changed
- `config/databases.json` takes named `connections`, each with its own `engine`, login and databases, so one node can watch several database servers or accounts. `/databases/overview` returns a `connections` list with a per-connection `error`, and `/databases/processes` takes `connection=`, which is required. The single-connection format of 0.3.0 fails startup with `configuration_invalid`. **Action:** wrap the file in `"connections": {"<name>": …}`, or convert a pre-0.3.0 file with `scripts/migrate-databases-config.php`, and update the dashboard at the same time
- A JSON config file whose top level is a list instead of an object fails startup with `configuration_invalid`, and the boot log now says why a file is not valid JSON
- `config.env` values are validated, and an invalid one fails startup with `configuration_invalid` instead of being silently replaced: integer settings (rate limits and windows, Redis port and database, `DASHBOARD_CLOCK_SKEW_WINDOW`) must be whole numbers in range instead of becoming `0`, on/off settings `ON`/`OFF`, `TRUE`/`FALSE`, `YES`/`NO` or `1`/`0` instead of meaning off, `RATE_LIMIT_STORAGE` one of `file`, `redis` and `apcu` instead of falling back to `file`, and `MONOLOG_LEVEL` a Monolog level name instead of falling back to `debug`

- With rate limiting on, `RATE_LIMIT_STORAGE=redis` or `apcu` without the PHP extension fails startup with `configuration_invalid` instead of denying every request with `429`

### Removed
- `make logs-db` and the database steps in `make docker-setup`; the Docker setup has no database container
- `DOMAIN` and `DIR`, which nothing read; `DOMAIN` is no longer required
- `DISPLAY_ERRORS` and `LOG_LEVEL`, which nothing read; PHP errors are never displayed and go to `logs/php-error.log`, and `error_reporting` comes from `php.ini` (`docker/php/php-*.ini` in Docker)
- `RATE_LIMIT_LOGIN_*` and `RATE_LIMIT_DASHBOARD_*`, which the node never applied; they are ignored and can be deleted from `config.env`

### Fixed
- `/databases/*` responses no longer include the database host, port or driver error; the node logs them as a warning instead
- `RATE_LIMIT_AUTH_FAILED_*` only logged when the limit was exceeded; a client IP over the limit now gets `429` on authenticated routes, valid credentials included, until the window passes
- Without a writable `logs/`, the boot log went to `/tmpmata-boot.log` instead of `/tmp/mata-boot.log`
- A `0` in `config.env` was treated as unset and replaced by the default, so `RATE_LIMIT_ENABLED=0` left rate limiting on
- Responses containing invalid UTF-8, such as a Latin-1 README or log line, were sent as an empty body with status `200`; invalid bytes are now replaced with U+FFFD

## [0.4.0] - 2026-10-10

### Added
- `monitoring.libraryaudit` per-app switch in `apps.json` (default `true`). Transport-only: `false` tells the dashboard to skip its scheduled Composer audit and library security events for the app. Update the node and the dashboard before setting it: older nodes reject the key in `apps.json`, and older dashboards reject an `/apps` payload that contains it

## [0.3.0] - 2026-10-10

Instance-scoped database monitoring with MariaDB/MySQL and PostgreSQL drivers.
Pairs with MATA Dashboard 0.7.0.

### Added
- `/databases/overview`: instance-wide connection status, cheap enough for collection
- `/databases/processes`: session list limited to monitored schemas, with `database`, `active_only` and `limit` parameters and a `visibility` field; returns the statement keyword, never query text
- PostgreSQL support for `/databases/*` (`"engine": "pgsql"`); the Docker images include `pdo_pgsql`, and `postgres` processes count toward the SQL process count in `/server/data`

### Changed
- `config/databases.json` has a new shape: `engine`, one `connection` and the monitored `databases`. The old format fails startup with `configuration_invalid`; see `docs/configuration.md`

### Removed
- `/server/databases` and `/{dbname}/database-status`, `-connections`, `-processes`, `-threads`. The SQL process count remains in `/server/data`
- Unused mail configuration: `config/mail.config.env` is no longer required or loaded, and the `symfony/mailer` dependency is gone. Existing `config/mail.config.env` files can be deleted

## [0.2.0] - 2026-10-09

First public release. Earlier versions were developed privately; this entry
summarizes what landed since then. Pairs with MATA Dashboard 0.6.0.

### Added
- Namespaced server endpoints, including cron job heartbeat monitoring
- Application sessions (file and Redis backends), Composer audit endpoint, and per-app monitoring controls
- Fatal log cursors and a configurable log readout limit
- IP allowlist enforcement and persistent nonce replay protection
- Configuration reference covering all five config files (`docs/configuration.md`) and a custom log parser guide (`docs/log-parsers.md`)

### Changed
- App endpoints return curated metadata instead of the raw app configuration
- Shell commands run through a bounded process runner that kills process groups on timeout
- Rate limiting fails closed when its storage is unavailable
- Maintenance mode blocks authenticated API routes
- The `live` environment is renamed to `prod`
- The legacy `alarms_enabled`, `ping`, `error_monitoring_enabled` and `logsize_monitoring_enabled` app settings are removed; see the configuration reference for replacements
- `make` targets that start containers create the external `mata-network` if it is missing, so the node no longer depends on MATA Dashboard being set up first
- Relicensed under the Apache License 2.0

### Fixed
- Absolute log and watcher paths, configured README paths, and disabled apps are handled correctly
- Composer audits run isolated, with plugins and scripts disabled and the configured PHP binary
- Malformed log input and startup configuration errors no longer break requests

## [0.1.0] - 2026-03-14

Initial private release.
