<!-- PROJECT LOGO -->
<div align="center">
  <a href="https://github.com/mata-sh/mata-node">
    <img src="docs/images/logo.png" alt="Logo" width="200">
  </a>

<h3 align="center">MATA Node</h3>

<p align="center">
    The lightweight, read-only companion to the <a href="https://github.com/mata-sh/mata-dashboard">MATA Dashboard</a>.
</p>
</div>

MATA Node runs on each monitored host and exposes a read-only, HMAC-authenticated API that the
dashboard polls. It never initiates connections and keeps no metric history; the only state on disk
is caches, used nonces, rate-limit counters and log read positions. It has no UI, alerting or
remote execution. For how dashboard and nodes fit together, see the
[MATA Dashboard](https://github.com/mata-sh/mata-dashboard) documentation.

## What is exposed

**Snapshots:** CPU / RAM / disk, processes, recent log lines and log status, session counts,
Composer packages and audits, application metadata, cron heartbeats, optional database status.

**Never exposed:** secrets, environment variables, database contents, source code.

Security: pull-only HMAC requests with a short time window and nonce replay protection, rate
limiting, optional IP allowlist; HTTPS expected in production. Details in the dashboard's
[security architecture](https://github.com/mata-sh/mata-dashboard/blob/main/docs/architecture/security.md#node-authentication).
Report vulnerabilities privately; see [`SECURITY.md`](SECURITY.md).

## API

`GET /health` is public. All other routes require the `X-Mata-Auth` header:

```text
X-Mata-Auth: <server_id>|<unix_timestamp>|<nonce>|<hex HMAC-SHA256(server_id|timestamp|nonce, api_key)>
```

| Scope | Routes |
| --- | --- |
| Server | `/server/data`, `/server/info`, `/server/php`, `/server/cronjobs` |
| Apps | `/apps`, `/apps/libraries`, `/apps/composer-audit` |
| Per app | `/{app}/readme`, `/{app}/sessions`, `/{app}/logs`, `/{app}/logs-status`, `/{app}/libraries`, `/{app}/composer-audit` |
| Databases | `/databases/overview`, `/databases/processes?connection=&database=&active_only=&limit=` |

All routes are `GET`. `/apps` responses depend on each app's `monitoring` switches; see
[app metadata responses](docs/configuration.md#app-metadata-responses).

## Quick start

Requires Docker, or PHP 8.3+ with a web server that serves `public/`.

**Docker** (development setup; for production with `make prod-start` see
[`docker/README.md`](docker/README.md#production)):

```bash
git clone https://github.com/mata-sh/mata-node.git && cd mata-node
make dev-start
curl http://localhost:3310/health
```

**Without Docker:**

```bash
git clone https://github.com/mata-sh/mata-node.git && cd mata-node
make bootstrap-config   # creates all config files from config/example/
composer install
```

Point the web root to `public/` using one of the configs in
[`docs/examples/webserver-configs/`](docs/examples/webserver-configs/).

## Configuration

Every file in `config/example/` needs a counterpart in `config/`; `make bootstrap-config` and
`make dev-start` create them. If one is still missing, the node copies it from the example and
answers that request with `503` (`configuration_missing`). At minimum, set the dashboard
credentials in `config/config.env`:

```env
DASHBOARD_SERVER_ID=prod-web-01
DASHBOARD_API_KEY=your-shared-secret
```

`DASHBOARD_SERVER_ID` must equal this node's entry key in the dashboard's `config/servers.json`
(the property name, e.g. `"prod-web-01": { ... }`), and `DASHBOARD_API_KEY` the `api_key` stored
under the same name in the dashboard's `config/servers.secrets.json`. Dashboards up to 0.8.0 keep
it as `api_key` in the `servers.json` entry instead.

Then describe the watched apps in `config/apps.json`. Everything else, including IP allowlisting,
maintenance mode and databases, is in the [configuration reference](docs/configuration.md).
Other log formats can be defined in YAML in `config/log-formats/`; see [log formats](docs/log-parsers.md).

Check the configuration with `make mata ARGS="config validate"` and an app's logs with
`make mata ARGS="log test <app>"`; see the [command line](docs/cli.md).

`/health` returns `200` when healthy and `503` otherwise. On a startup failure every route returns
`503` with `reason` `configuration_missing`, `configuration_invalid` or `initialization_failed`.

## License

Apache License 2.0. See [`LICENSE`](LICENSE) and [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md).
