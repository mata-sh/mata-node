# MATA Dashboard - Docker Architecture Documentation

This document provides a comprehensive overview of the Docker architecture for the MATA Dashboard application.

## Table of Contents

- [Overview](#overview)
- [Directory Structure](#directory-structure)
- [Environment Separation](#environment-separation)
- [Quick Start](#quick-start)
  - [Development](#development)
  - [Production](#production)
- [Configuration Files](#configuration-files)
- [Architecture Details](#architecture-details)
- [Common Operations](#common-operations)
- [Troubleshooting](#troubleshooting)
- [Best Practices](#best-practices)

## Overview

The MATA Dashboard uses a multi-environment Docker setup with:

- **Multi-stage builds** for optimized production images
- **Environment separation** (dev, demo, prod)
- **Docker Compose** for orchestration
- **Apache + PHP 8.3** runtime
- **MySQL 8.0** database
- **Automated cron jobs** for data collection and cleanup

## Directory Structure

```
project/
├── .env                              # Docker orchestration config (gitignored)
├── .env.example                      # Template for .env
├── .dockerignore                     # Build context exclusions
├── Dockerfile                        # Multi-stage production build
├── Dockerfile.dev                    # Development build
├── docker-compose.yml                # Base configuration
├── docker-compose.dev.yml            # Development overrides
├── docker-compose.prod.yml           # Production configuration
│
├── config/                           # Application configuration
│   ├── config.env                    # App settings
│   ├── db.config.env                 # Database connection
│   ├── mail.config.env               # Email configuration
│   ├── dev/                          # Development JSON configs
│   │   └── users.json                # Dev user credentials
│   ├── prod/                         # Production JSON configs
│   └── demo/                         # Demo JSON configs
│
├── docker/                           # Docker configuration files
│   ├── apache/
│   │   └── 000-default.conf          # Apache vhost config
│   ├── php/
│   │   ├── php.ini                   # Default PHP config
│   │   ├── php-dev.ini               # Development PHP config
│   │   └── php-prod.ini              # Production PHP config
│   ├── cron/
│   │   └── crontab                   # Scheduled tasks
│   ├── mysql/
│   │   ├── my.cnf                    # MySQL tuning
│   │   └── init.sql                  # Database initialization
│   └── README.md                     # This file
│
└── scripts/                          # Helper scripts
    ├── docker-dev.sh                 # Start dev environment
    ├── docker-prod.sh                # Deploy production
    └── setup-dev-env.sh              # First-time setup
```

## Environment Separation

### Configuration Layers

The application uses **two separate configuration layers**:

#### 1. Docker Orchestration (`.env`)
- Used by `docker-compose.yml`
- Contains: ports, database credentials, `APP_ENV`
- Controls which containers start and how they connect
- **Not loaded by the PHP application**

Example:
```bash
COMPOSE_PROJECT_NAME=mata-dashboard
WEB_PORT=8080
APP_ENV=dev
DB_HOST=db
DB_USERNAME=mata_user
DB_PASSWORD=secret
```

#### 2. Application Configuration (`config/*.env`)
- Loaded by PHP application via `EnvConfigLoader`
- Contains: domain, template settings, logging, mail config
- Controls application behavior
- Independent of Docker (can run without containers)

Files:
- `config/config.env` - General app settings
- `config/db.config.env` - Database connection details
- `config/mail.config.env` - Email configuration

#### 3. Environment-Specific Data (`config/{env}/*.json`)
- Loaded based on `APP_ENV` value (dev, demo, prod)
- Contains: users, servers, monitoring settings
- Runtime configuration data

### Environment Comparison

| Feature | Development | Production |
|---------|------------|------------|
| **Dockerfile** | `Dockerfile.dev` | `Dockerfile` (multi-stage) |
| **Compose File** | `docker-compose.dev.yml` | `docker-compose.prod.yml` |
| **Source Code** | Volume mounted (hot reload) | Baked into image |
| **Composer** | Installed in container | Not in final image |
| **Dependencies** | Includes dev deps | Production only |
| **OPcache** | Disabled | Enabled with JIT |
| **Error Display** | On (1) | Off (0) |
| **Logging** | Debug level | Errors only |
| **MySQL** | In-memory (tmpfs) | Persistent volume |
| **PHP Memory** | 512M | 256M |
| **Xdebug** | Available (commented) | Not installed |

## Quick Start

> **💡 Recommended**: Use Makefile targets for the best developer experience. Run `make help` for all commands.

### Development

#### Using Makefile (Recommended)
```bash
# 1. First-time setup
make setup

# 2. Start development environment
make dev

# 3. Follow logs (optional)
make logs

# Access application: http://localhost:8080
# Login: dev / password
```

#### Direct Docker Compose (Alternative)
```bash
# 1. Create .env file
cp .env.example .env

# 2. Ensure APP_ENV is set to dev
sed -i 's/APP_ENV=.*/APP_ENV=dev/' .env

# 3. Start containers
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# 4. Access application
# http://localhost:8080
# Login: dev / password
```

### Production

#### Using Makefile (Recommended)
```bash
# 1. Create and configure .env
cp .env.example .env
# Edit .env: set APP_ENV=prod, strong passwords, etc.

# 2. Deploy
make prod

# Production includes automatic safety checks for:
# - Missing .env file
# - Default passwords
# - APP_ENV setting
```

#### Direct Docker Compose (Alternative)
```bash
# 1. Create production .env
cp .env.example .env

# 2. Update .env with production values:
# - APP_ENV=prod
# - Strong DB_PASSWORD
# - Strong MYSQL_ROOT_PASSWORD
# - DISPLAY_ERRORS=0

# 3. Deploy
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

## Configuration Files

### Apache Configuration (`docker/apache/000-default.conf`)

**Document Root**: `/var/www/html/public`

**Security Features**:
- Blocks access to: `config/`, `src/`, `vendor/`, `logs/`, `temp/`
- Denies: `.env`, `.json`, `.yml`, `.xml`, `.ini` files
- Security headers: X-Frame-Options, CSP, X-Content-Type-Options
- SSL ready (commented out, enable for HTTPS)

**Performance**:
- Compression enabled (`mod_deflate`)
- Browser caching configured (`mod_expires`)
- PHP settings: 64M uploads, 300s timeout, 256M memory

### PHP Configuration

#### Default (`docker/php/php.ini`)
- Balanced settings for general use
- OPcache enabled, moderate settings
- Error logging to `/var/www/html/logs/php-error.log`
- Session storage in `/var/www/html/temp/sessions`

#### Development (`docker/php/php-dev.ini`)
- OPcache **disabled** (immediate code changes)
- Error display **enabled**
- 512M memory limit
- Xdebug configuration (commented out)
- Verbose error reporting (E_ALL)

#### Production (`docker/php/php-prod.ini`)
- OPcache **enabled with JIT**
- Output compression (gzip level 6)
- Error display **disabled**
- Strict session security (secure flag, strict SameS ite)
- `open_basedir` restriction for security
- Optimized realpath cache (600s TTL)

### MySQL Configuration (`docker/mysql/my.cnf`)

**Performance Tuning**:
- InnoDB buffer pool: 256M
- Max connections: 200
- Query cache: 32M
- Slow query log: 2s threshold

**Security**:
- Skip name resolution
- STRICT transaction modes
- Binary logging disabled (can enable for replication)

### Cron Jobs (`docker/cron/crontab`)

**Data Collection**:
- Every 5 min: Fetch server data
- Every 15 min: Check cronjob heartbeats

**Cleanup Tasks**:
- 2 AM daily: Delete logs older than 30 days
- 3 AM daily: Delete cache older than 7 days
- 4 AM daily: Delete sessions older than 1 day
- 5 AM Sunday: Delete template cache older than 7 days
- 6 AM Sunday: Compress and rotate logs

## Architecture Details

### Multi-Stage Production Build

**Stage 1: Builder**
- Installs Composer
- Installs production dependencies (`--no-dev`)
- Optimizes autoloader

**Stage 2: Production Runtime**
- No Composer in final image
- No dev dependencies
- Only runtime packages
- Smaller image size (~200MB reduction)

**Benefits**:
- Faster deployment (smaller images)
- Better security (fewer tools in production)
- Optimized layer caching

### Volume Mounting Strategy

#### Development
```yaml
volumes:
  - .:/var/www/html:cached          # Full source code
  - /var/www/html/vendor            # Anonymous volume for vendor/
```
- Source code mounted for hot reload
- Vendor directory in anonymous volume (avoids permission issues)

#### Production
```yaml
volumes:
  - ./config:/var/www/html/config:ro   # Read-only config
  - ./logs:/var/www/html/logs          # Writable logs
  - ./temp:/var/www/html/temp          # Writable temp files
```
- Source code baked into image (not mounted)
- Only persistent directories mounted

### Health Checks

**Development**: 30s interval, 10s timeout, 3 retries, 10s start period
**Production**: 30s interval, 10s timeout, 5 retries, 30s start period

Checks HTTP GET to `http://localhost/` for 200 OK response.

## Common Operations

### View Logs
```bash
# Using Makefile (Recommended)
make logs           # Follow all logs
make logs-web       # Web container only
make logs-db        # Database only

# Direct Docker Compose
docker-compose logs -f
docker-compose logs -f web
docker-compose logs -f db
```

### Rebuild Containers
```bash
# Using Makefile
make dev-rebuild        # Development
make prod-rebuild       # Production

# Direct Docker Compose
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker-compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache
```

### Execute Commands in Container
```bash
# Using Makefile
make shell              # Enter web container as root
make shell-www          # Enter as www-data user
make db-shell           # MySQL shell

# Direct Docker Compose
docker-compose exec web bash
docker-compose exec -u www-data web bash
docker-compose exec db mysql -u root -p
```

### Database Operations
```bash
# Using Makefile
make db-backup                              # Backup to backups/
make db-restore FILE=backups/backup.sql     # Restore from file
make db-migrate                             # Run migrations
make db-status                              # Migration status

# Direct Docker Compose
docker-compose exec db mysqldump -u root -p mata_dashboard > backup.sql
docker-compose exec -T db mysql -u root -p mata_dashboard < backup.sql
```

### Clean Up
```bash
# Using Makefile
make down               # Stop all containers
make clean              # Remove everything (with confirmation)
make docker-clean       # Docker system prune

# Direct Docker Compose
docker-compose down
docker-compose down -v  # WARNING: deletes data!
docker system prune -a
```

## Troubleshooting

### Permission Issues

**Problem**: Files created in container have wrong ownership

**Solution**:
```bash
# Fix ownership (run on host)
sudo chown -R $USER:$USER .

# Or run in container as www-data
docker-compose exec -u www-data web bash
```

### Port Already in Use

**Problem**: `Bind for 0.0.0.0:8080 failed: port is already allocated`

**Solution**:
```bash
# Change WEB_PORT in .env
echo "WEB_PORT=8081" >> .env

# Or stop conflicting service
lsof -ti:8080 | xargs kill -9
```

### Database Connection Fails

**Problem**: `SQLSTATE[HY000] [2002] Connection refused`

**Checklist**:
1. Is DB container running? `docker-compose ps`
2. Check DB logs: `docker-compose logs db`
3. Verify credentials in `.env` match `config/db.config.env`
4. Wait for MySQL to fully start (can take 30s)

**Solution**:
```bash
# Restart database
docker-compose restart db

# Check MySQL is ready
docker-compose exec db mysqladmin ping -h localhost -u root -p
```

### OPcache Not Clearing (Dev)

**Problem**: Code changes not reflecting

**Verify**:
```bash
# Check if using Dockerfile.dev
docker-compose -f docker-compose.yml -f docker-compose.dev.yml ps

# Verify OPcache is disabled
docker-compose exec web php -i | grep opcache.enable
```

**Solution**:
```bash
# Rebuild with dev Dockerfile
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# Or manually clear OPcache
docker-compose exec web php -r "opcache_reset();"
```

### Container Keeps Restarting

**Problem**: Container in restart loop

**Debug**:
```bash
# Check container logs
docker-compose logs --tail=100 web

# Check health status
docker inspect --format='{{json .State.Health}}' mata-dashboard

# Run container without daemon to see errors
docker-compose up web
```

## Best Practices

### Development

1. **Always use dev compose override**
   ```bash
   docker-compose -f docker-compose.yml -f docker-compose.dev.yml up
   ```

2. **Don't commit `.env`**
   - Use `.env.example` as template
   - Keep sensitive data out of git

3. **Use volume mounts for hot reload**
   - Code changes reflect immediately
   - No rebuild needed for PHP changes

4. **Check logs regularly**
   - Application logs: `logs/app.log`
   - PHP errors: `logs/php-error.log`
   - Docker logs: `docker-compose logs`

### Production

1. **Use strong passwords**
   - Generate with: `openssl rand -base64 32`
   - Never use defaults

2. **Enable HTTPS**
   - Use reverse proxy (nginx/Caddy)
   - Configure SSL in Apache config
   - Uncomment SSL section in `000-default.conf`

3. **Resource limits**
   - Set in `docker-compose.prod.yml`
   - Monitor with `docker stats`

4. **Backup strategy**
   - Daily database backups
   - Backup `config/`, `logs/` directories
   - Test restore procedure

5. **Security hardening**
   - Use Docker secrets for sensitive data
   - Enable firewall (ufw/iptables)
   - Regular security updates
   - Monitor logs for suspicious activity

6. **Monitoring**
   - Set up health check endpoints
   - Use monitoring tools (Prometheus, Grafana)
   - Alert on container failures
   - Track resource usage

---

## Additional Resources

- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [PHP Docker Official Image](https://hub.docker.com/_/php)
- [MySQL Docker Official Image](https://hub.docker.com/_/mysql)
- [Apache HTTP Server Documentation](https://httpd.apache.org/docs/)

---

**Version**: 1.0
**Last Updated**: 2025-01-01
**Maintained By**: MATA Dashboard Team
