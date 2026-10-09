# Docker

MATA Node runs as a single `mata-web` container (PHP 8.3 + Apache) on port `3310`. It has no
database container; databases listed in `config/databases.json` are reached over the network.

## Files

| File | Purpose |
| --- | --- |
| `.env` | Compose settings: `COMPOSE_PROJECT_NAME`, `WEB_PORT`, `PHP_VERSION`, `COMPOSER_VERSION`, `APP_ENV` |
| `docker-compose.yml` | Base service, port mapping, env files, external `mata-network` |
| `docker-compose.dev.yml` | `Dockerfile.dev`, source bind mount, `vendor` named volume |
| `docker-compose.prod.yml` | Multi-stage `Dockerfile`, read-only `config/`, log rotation, `no-new-privileges` |
| `docker/apache/000-default.conf` | Vhost: document root `public/`, security headers, denies `config/`, `src/`, `vendor/`, `logs/`, `temp/` |
| `docker/php/php-{dev,prod}.ini` | PHP settings per environment |
| `docker/cron/crontab` | `www-data` crontab, empty by default |
| `docker/entrypoint-dev.sh` | Dev only: maps `www-data` to the host UID/GID, creates `temp/` and `logs/`, runs `composer install` if `vendor/` is empty |

The app itself is configured in `config/`; see the [configuration reference](../docs/configuration.md).

## Development

```bash
make dev-start      # runs docker-setup on first start
curl http://localhost:3310/health
```

The `make` targets that start containers create the external `mata-network` (shared with MATA
Dashboard) if it does not exist yet.

`make docker-setup` creates `.env` and the `config/` files from their examples and asks for
`DASHBOARD_API_KEY`. Source changes apply immediately. Env file changes do not: run
`make dev-recreate` (`make dev-restart` keeps the old environment).

## Production

```bash
cp .env.example .env
make bootstrap-config             # then edit config/
make prod-start
```

The image contains the code and production dependencies only. `config/` is mounted read-only;
`logs/` and `temp/` are mounted writable. Terminate TLS at a reverse proxy in front of the
container.

## Commands

| Command | Action |
| --- | --- |
| `make dev-start` / `dev-stop` | Start / stop dev |
| `make dev-recreate` | Recreate containers to reload env files |
| `make dev-rebuild` / `prod-rebuild` | Rebuild without cache |
| `make prod-start` / `prod-stop` | Start / stop production |
| `make logs` / `logs-app` / `logs-php` | Container / app / PHP error logs |
| `make shell` / `shell-www` | Shell as root / `www-data` |
| `make test` | PHPUnit in the running container |
| `make composer-rebuild` | Drop the `vendor` volume and reinstall |
| `make docker-clean` | Remove this project's containers, volumes and images |

## Troubleshooting

- **`network mata-network declared as external, but could not be found`**: you ran
  `docker-compose` directly; run `make docker-network` once, or use the `make` targets.
- **Port 3310 in use**: set `WEB_PORT` in `.env`.
- **`/health` returns 503 with `configuration_missing`**: run `make bootstrap-config`; all five
  files in `config/` must exist.
- **Config change not picked up**: `make dev-recreate`.
