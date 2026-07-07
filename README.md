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
- Whitelisted application metadata
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
- `GET /server-data`
- `GET /server-info`
- `GET /server-php`
- `GET /apps`
- `GET /apps/libraries`
- `GET /apps/composer-audit`
- `GET /{app}/readme`
- `GET /{app}/session-count`
- `GET /{app}/logs`
- `GET /{app}/logs-status`
- `GET /{app}/app-config`
- `GET /{app}/libraries`
- `GET /server-databases`
- `GET /{dbname}/database-status`
- `GET /{dbname}/database-connections`
- `GET /{dbname}/database-processes`
- `GET /{dbname}/database-threads`

### Authentication format

Authenticated requests use a single header:

```text
X-Mata-Auth: <dashboard_server_id>|<unix_timestamp>|<nonce>|<hex_hmac_sha256>
```

The node validates the dashboard server ID, timestamp window, nonce, and HMAC signature.

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
- `config/apps.json` — watched applications, see [configuration reference](docs/configuration.md#configappsjson)
- `config/transmit.json` — transmitted metadata and limits, see [configuration reference](docs/configuration.md#configtransmitjson)

At minimum, set the shared dashboard credentials in `config/config.env`:

```env
DASHBOARD_SERVER_ID=dashboard
DASHBOARD_API_KEY=your-shared-secret
```

## Health check

- Endpoint: `/health`
- No authentication required
- Returns `200` when healthy, `503` when unhealthy

Example response:

```json
{
  "status": "healthy",
  "timestamp": "2025-01-25 12:34:56",
  "checks": {
    "logs_writable": true,
    "cache_writable": true,
    "config_exists": true
  }
}
```

## License

MIT
