# Changelog

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- Database process lists no longer include query text: `info` holds only the statement keyword (e.g. `SELECT`), since full SQL can contain application data or credentials. Rows now use lowercase keys (`id`, `user`, `host`, `db`, `command`, `time`, `state`, `info`), which the dashboard's process modal already expects

### Removed
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
