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

## Monitoring the host from a container

The node reports what it can see from inside its container, which by default is less than a
native install sees:

| Data | Seen by default | To see the host |
| --- | --- | --- |
| CPU, memory | Host values (`/proc` is shared with the host) | Nothing to do |
| Disk | The container's filesystem at `check_disk_space_path` | Mount the host path and point `check_disk_space_path` at it |
| Process count, PHP processes | The container's own processes only | `pid: host` |
| Logs, sessions, README, Composer files, cron heartbeats | Nothing outside the node | Read-only bind mounts |

App paths in `apps.json` resolve against the node's parent directory, which is `/var/www` in the
image. Mount each app below it and keep the relative paths:

```yaml
services:
  mata-web:
    pid: host
    volumes:
      - /srv/shop:/var/www/shop:ro    # apps.json: "path": "shop", "logs": ["shop/var/log/prod.log"]
      - /srv:/host/srv:ro             # transmit.json: "check_disk_space_path": "/host/srv"
```

- `make prod-start` names its Compose files explicitly, so a `docker-compose.override.yml` is not
  read. Add the lines to `docker-compose.prod.yml`.
- Mounted files must be readable by `www-data` (UID 33) inside the container. Check with
  `make shell-www`.
- `pid: host` shows the node every host process with its command line, as a native install sees
  them.

Applications that run in their own containers are visible only through files they write to the
host. Point their log directory (`storage/logs`, `var/log`) at a host directory and mount that
into the node. Output that goes only to stdout/stderr (`docker logs`), and Composer files baked
into an image, are not visible to the node.

If the mounts get unwieldy, run the node natively on the host instead; it then reads everything
its user can read.

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
