<!-- PROJECT LOGO -->
<div align="center">
  <a href="https://github.com/mata-sh/mata-node">
    <img src="docs/images/logo.png" alt="Logo" width="200">
  </a>

<h3 align="center">MATA Node</h3>

<p align="center">
    MATA Node is the lightweight, stateless companion to the <a href="https://github.com/mata-sh/mata-dashboard">MATA Dashboard</a>.
</p>

<p>
It exposes a **read-only API** that the dashboard polls for monitoring data.
</p>
</div>

## Mental Model

- **Dashboard pulls data from nodes.**
- **Nodes never initiate connections.**
- **Nodes are stateless and read-only.**

MATA Node runs on each monitored environment and answers authenticated requests from the dashboard. It does not send data outbound, store monitoring history, or perform remote actions.

## What it does

- Exposes read-only monitoring endpoints
- Returns snapshots about system and application state
- Stays lightweight enough for shared hosting and constrained VPS environments

## What it does not do

- No UI
- No persistence
- No outbound connections
- No alerting
- No remote execution

## What is exposed

**Snapshots may include:**
- CPU / RAM / disk usage
- Process snapshots
- Recent log lines
- Session counts
- Composer package inventory
- Static application metadata and log/session status
- Optional database status snapshots

**Not exposed:**
- Secrets
- Environment variables
- Database contents
- Source code

## Security model

- **Read-only API**
- **Pull-only traffic**: dashboard → node
- **HMAC authentication** via `X-Mata-Auth`
- **Short time window** for request validation
- **Replay protection** via nonce checking
- **HTTPS recommended / expected in production**
- **Optional IP allowlisting**
- **Rate limiting** on API access

## API

### Public
- `GET /health` — health check

### Authenticated
- `GET /server/data`
- `GET /server/info`
- `GET /server/php`
- `GET /server/cronjobs`
- `GET /apps`
- `GET /apps/libraries`
- `GET /apps/composer-audit`
- `GET /{app}/readme`
- `GET /{app}/sessions`
- `GET /{app}/logs`
- `GET /{app}/logs-status`
- `GET /{app}/libraries`
- `GET /server/databases`
- `GET /{dbname}/database-status`
- `GET /{dbname}/database-connections`
- `GET /{dbname}/database-processes`
- `GET /{dbname}/database-threads`

### Authentication format

Authenticated requests use a single header:

```text
X-Mata-Auth: <server_id>|<unix_timestamp>|<nonce>|<hex_hmac_sha256>
```

The node validates the server ID, timestamp window, nonce, and HMAC signature. The signature is
`HMAC-SHA256(server_id|timestamp|nonce, api_key)`, so the API key itself never travels over the wire.
`<server_id>` is this node's entry key in the dashboard's `servers.json` (see below).

## Quick Start

### Requirements

- PHP 8.3+
- Docker / Docker Compose for the containerized setup
- Or any web server that can serve `public/` and run PHP 8.3+

### Docker

```bash
git clone <repository-url>
cd mata-node
make dev-start
```

Health check:

```bash
curl http://localhost:3310/health
```

### Non-Docker / constrained environments

```bash
git clone <repository-url>
cd mata-node
cp config/example/config.env.example config/config.env
cp config/example/apps.json.example config/apps.json
cp config/example/transmit.json.example config/transmit.json
composer install
```

Then:
- point your web root to `public/`
- use one of the example web server configs in `docs/examples/webserver-configs/`
- set `DASHBOARD_SERVER_ID` and `DASHBOARD_API_KEY` in `config/config.env`

### Minimal config

Main runtime settings live in:
- `.env` — Docker/infrastructure settings
- `config/config.env` — node application settings
- `config/apps.json` — watched applications, static environment metadata, optional status endpoints, sessions, logs, and composer metadata; see [configuration reference](docs/configuration.md#configappsjson)
- `config/transmit.json` — node operational limits, see [configuration reference](docs/configuration.md#configtransmitjson)

Redis session stores should use `sessions.count_key` when available so the node reads one maintained counter instead of scanning session keys.

At minimum, set the shared dashboard credentials in `config/config.env`:

```env
DASHBOARD_SERVER_ID=prod-web-01
DASHBOARD_API_KEY=your-shared-secret
```

`DASHBOARD_SERVER_ID` must exactly match this node's entry key in the dashboard's
`config/servers.json` — the JSON property name (e.g. `"prod-web-01": { ... }`), not a field
inside the entry. `DASHBOARD_API_KEY` must match that entry's `api_key` value. Renaming the
key in `servers.json` breaks authentication for this node until both sides agree again.

Optional app-level IP allowlisting is available for shared hosting:

```env
IP_WHITELIST_ENABLED=ON
ALLOWED_IPS=198.51.100.10,2001:db8::/32
TRUSTED_PROXIES=
```

By default, client IP detection uses `REMOTE_ADDR`. `X-Forwarded-For` is used only when `REMOTE_ADDR` matches `TRUSTED_PROXIES`. Do not add untrusted proxies.

To pause authenticated API access during maintenance:

```env
MAINTENANCE_MODE_ENABLED=ON
```

Maintenance mode leaves `/health` available and returns `503 Service Unavailable` with `Retry-After: 300` for all other routes.

## Health check

- Endpoint: `/health`
- No authentication required
- Returns `200` when healthy and `503` when unhealthy or when startup has not completed

Healthy response:

```json
{
  "status": "healthy",
  "timestamp": "2025-01-25 12:34:56",
  "checks": {
    "logs_writable": true,
    "cache_writable": true,
    "config_files_present": true
  },
  "missing_config_files": []
}
```

During startup failure, every endpoint (including `/health`) returns `503 Service Unavailable` with `Retry-After: 300`, `Cache-Control: no-store`, and a JSON body:

```json
{
  "status": "not_ready",
  "reason": "configuration_missing",
  "missing_config_files": ["apps.json"]
}
```

`reason` is `configuration_missing`, `configuration_invalid`, or `initialization_failed`. File names are basenames only.

## License

MIT
