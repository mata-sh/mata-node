# ============================================
# Colors (using git config for portability)
# ============================================

# Define colors using git's color system
COLOR_RESET := $(shell git config --get-color "" "reset")
COLOR_BOLD := $(shell git config --get-color "" "bold")
COLOR_RED := $(shell git config --get-color "" "red")
COLOR_GREEN := $(shell git config --get-color "" "green")
COLOR_YELLOW := $(shell git config --get-color "" "yellow")
COLOR_BLUE := $(shell git config --get-color "" "blue")
COLOR_CYAN := $(shell git config --get-color "" "cyan")
COLOR_MAGENTA := $(shell git config --get-color "" "magenta")

# Config bootstrap source/target files
ENV_CONFIG_FILES := config.env
JSON_CONFIG_FILES := apps.json transmit.json databases.json

# ============================================
# Help
# ============================================

# Default target - show help
.DEFAULT_GOAL := help

# Show all available commands
help:
	@echo "$(COLOR_CYAN)=====================================$(COLOR_RESET)"
	@echo "$(COLOR_BOLD)$(COLOR_CYAN)MATA Node - Makefile Commands$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)=====================================$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)ℹ️ Most commands require Docker. Run 'make setup' first.$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)ℹ️ For more commands: $(COLOR_CYAN)make docker-help$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BOLD)Local General (Git Hooks, Build):$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make bootstrap-config$(COLOR_RESET) - Create missing env/json config files from examples"
	@echo "  $(COLOR_GREEN)make setup$(COLOR_RESET)            - Full setup (composer deps + config bootstrap) [$(COLOR_MAGENTA)composer, git$(COLOR_RESET)]"
	@echo "  $(COLOR_GREEN)make install-hooks$(COLOR_RESET)    - Install git hooks [$(COLOR_MAGENTA)git$(COLOR_RESET)]"
	@echo ""
	@echo "$(COLOR_BOLD)Docker Dev Environment:$(COLOR_RESET)"
	@echo "  $(COLOR_CYAN)Setup:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make docker-setup$(COLOR_RESET)  - Docker environment setup only (optional - dev-start does this automatically)"
	@echo "  $(COLOR_CYAN)Development:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make dev-start$(COLOR_RESET)           - Start full Docker environment (auto-runs setup if needed)"
	@echo "    $(COLOR_GREEN)make dev-logs$(COLOR_RESET)      - Start dev and follow logs"
	@echo "    $(COLOR_GREEN)make dev-stop$(COLOR_RESET)      - Stop development"
	@echo "    $(COLOR_GREEN)make dev-recreate$(COLOR_RESET)  - Recreate containers (reload env files)"
	@echo "    $(COLOR_GREEN)make shell$(COLOR_RESET)         - Access web container"
	@echo "    $(COLOR_GREEN)make mata ARGS=…$(COLOR_RESET)   - Run a bin/mata-node command, e.g. ARGS=\"config validate\""
	@echo "  $(COLOR_CYAN)Testing:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make test$(COLOR_RESET)          - Run tests with PHPUnit"
	@echo "    $(COLOR_GREEN)make test-coverage$(COLOR_RESET) - Run tests with coverage"
	@echo "  $(COLOR_CYAN)Composer:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make composer-install$(COLOR_RESET)  - Install dependencies in container"
	@echo "    $(COLOR_GREEN)make composer-update$(COLOR_RESET)   - Update dependencies in container"
	@echo "    $(COLOR_GREEN)make composer-rebuild$(COLOR_RESET)  - Clear vendor and rebuild"
	@echo "  $(COLOR_CYAN)Monitoring:$(COLOR_RESET)"
	@echo "    $(COLOR_GREEN)make logs$(COLOR_RESET)          - Follow all container logs"
	@echo "    $(COLOR_GREEN)make ps$(COLOR_RESET)            - Show container status"
	@echo "    $(COLOR_GREEN)make down$(COLOR_RESET)          - Stop all containers"
	@echo "    $(COLOR_GREEN)make logs-app$(COLOR_RESET)      - Follow application log"
	@echo ""
	@echo "$(COLOR_BOLD)Docker Production Environment:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make prod$(COLOR_RESET)            - Deploy production"
	@echo "  $(COLOR_GREEN)make prod-stop$(COLOR_RESET)       - Stop production"
	@echo "  $(COLOR_RED)make clean$(COLOR_RESET)           - Remove everything (WARNING!)"
	@echo ""
	@echo "For more details: $(COLOR_CYAN)make docker-help$(COLOR_RESET)"

# ============================================
# Setup
# ============================================

# Bootstrap missing config files from config/example/*.example
bootstrap-config:
	@echo "$(COLOR_BLUE)📋 Bootstrapping config files from examples...$(COLOR_RESET)"
	@mkdir -p config logs
	@echo "$(COLOR_CYAN)Environment config files$(COLOR_RESET)"
	@for file in $(ENV_CONFIG_FILES); do \
		if [ ! -f "config/$$file" ]; then \
			cp "config/example/$${file}.example" "config/$$file"; \
			chmod 600 "config/$$file"; \
			echo "$(COLOR_GREEN)✅ Created config/$$file$(COLOR_RESET)"; \
		else \
			echo "$(COLOR_YELLOW)⚠️  config/$$file already exists, skipping$(COLOR_RESET)"; \
		fi; \
	done
	@echo "$(COLOR_CYAN)JSON config files$(COLOR_RESET)"
	@for file in $(JSON_CONFIG_FILES); do \
		if [ ! -f "config/$$file" ]; then \
			cp "config/example/$${file}.example" "config/$$file"; \
			chmod 600 "config/$$file"; \
			echo "$(COLOR_GREEN)✅ Created config/$$file$(COLOR_RESET)"; \
		else \
			echo "$(COLOR_YELLOW)⚠️  config/$$file already exists, skipping$(COLOR_RESET)"; \
		fi; \
	done

# Full environment setup (composer + config files)
setup:
	@$(MAKE) bootstrap-config
	@if [ -d temp ]; then \
		if [ ! -w temp ]; then \
			echo "$(COLOR_YELLOW)⚠️  temp exists but is not writable; skipping temp/cache creation$(COLOR_RESET)"; \
		else \
			mkdir -p temp/cache; \
		fi; \
	else \
		if mkdir -p temp/cache 2>/dev/null; then \
			:; \
		else \
			echo "$(COLOR_YELLOW)⚠️  Could not create temp/cache; set TEMP_DIR in config/config.env or create manually$(COLOR_RESET)"; \
		fi; \
	fi
	@echo ""
	@echo "$(COLOR_CYAN)🔑 Dashboard API Key$(COLOR_RESET)"
	@read -p "$(COLOR_CYAN)Paste DASHBOARD_API_KEY from your dashboard (or press Enter to skip): $(COLOR_RESET)" dash_key; \
	if [ -n "$$dash_key" ]; then \
		tmpfile=$$(mktemp); \
		awk -v key="$$dash_key" 'BEGIN{found=0} \
			/^DASHBOARD_API_KEY=/{print "DASHBOARD_API_KEY=" key; found=1; next} \
			{print} \
			END{if(!found) print "DASHBOARD_API_KEY=" key}' \
			config/config.env > $$tmpfile; \
		mv $$tmpfile config/config.env; \
		chmod 600 config/config.env; \
		echo "$(COLOR_GREEN)✅ Updated DASHBOARD_API_KEY in config/config.env$(COLOR_RESET)"; \
		if docker-compose ps mata-web 2>/dev/null | grep -q "Up"; then \
			echo "$(COLOR_YELLOW)⚠️  Containers are already running. Env files are only read at container creation — run '$(COLOR_CYAN)make dev-recreate$(COLOR_RESET)$(COLOR_YELLOW)' to apply the new key.$(COLOR_RESET)"; \
		fi; \
	else \
		echo "$(COLOR_YELLOW)⚠️  Skipped. Set DASHBOARD_API_KEY manually in config/config.env$(COLOR_RESET)"; \
	fi
	@echo ""
	@echo "$(COLOR_CYAN)📋 Webserver Configuration$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)Which webserver will you use?$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)1)$(COLOR_RESET) Apache (shared hosting) - .htaccess"
	@echo "  $(COLOR_GREEN)2)$(COLOR_RESET) Apache (VirtualHost) - apache-vhost.conf"
	@echo "  $(COLOR_GREEN)3)$(COLOR_RESET) Nginx - nginx-site.conf"
	@echo "  $(COLOR_GREEN)4)$(COLOR_RESET) Caddy - Caddyfile"
	@echo "  $(COLOR_GREEN)5)$(COLOR_RESET) Skip (I'll configure manually)"
	@read -p "$(COLOR_CYAN)Select option [1-5]: $(COLOR_RESET)" webserver; \
	case $$webserver in \
		1) \
			if [ ! -f "public/.htaccess" ]; then \
				cp docs/examples/webserver-configs/.htaccess public/.htaccess; \
				echo "$(COLOR_GREEN)✅ Created public/.htaccess$(COLOR_RESET)"; \
				echo "$(COLOR_BLUE)ℹ️  .htaccess is ready. Ensure mod_rewrite is enabled.$(COLOR_RESET)"; \
			else \
				echo "$(COLOR_YELLOW)⚠️  public/.htaccess already exists, skipping$(COLOR_RESET)"; \
			fi; \
			;; \
		2) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config to a new site file:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/apache-vhost.conf /etc/apache2/sites-available/mata-node.conf$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Then enable and reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo a2ensite mata-node && sudo systemctl reload apache2$(COLOR_RESET)"; \
			;; \
		3) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config to a new site file:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/nginx-site.conf /etc/nginx/sites-available/mata-node$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Then enable and reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo ln -s /etc/nginx/sites-available/mata-node /etc/nginx/sites-enabled/$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo systemctl reload nginx$(COLOR_RESET)"; \
			;; \
		4) \
			echo "$(COLOR_BLUE)ℹ️  Copy example config (review before overwriting existing Caddyfile):$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo cp docs/examples/webserver-configs/Caddyfile /etc/caddy/Caddyfile$(COLOR_RESET)"; \
			echo "$(COLOR_BLUE)   Or append to existing Caddyfile, then reload:$(COLOR_RESET)"; \
			echo "$(COLOR_CYAN)   sudo systemctl reload caddy$(COLOR_RESET)"; \
			echo "$(COLOR_YELLOW)   Note: Caddy will automatically obtain SSL certificates$(COLOR_RESET)"; \
			;; \
		5) \
			echo "$(COLOR_YELLOW)⚠️  Skipped. See docs/examples/webserver-configs/README.md for manual setup$(COLOR_RESET)"; \
			;; \
		*) \
			echo "$(COLOR_RED)❌ Invalid option. See docs/examples/webserver-configs/README.md$(COLOR_RESET)"; \
			;; \
	esac
	@echo ""
		@echo "$(COLOR_BLUE)📦 Installing Composer dependencies...$(COLOR_RESET)"
	@if [ ! -f composer.json ]; then \
		echo "$(COLOR_RED)❌ composer.json not found$(COLOR_RESET)"; \
		exit 1; \
	fi
	@if command -v composer >/dev/null 2>&1; then \
		composer install; \
	elif [ -f "$$HOME/.composer/composer" ]; then \
		$$HOME/.composer/composer install; \
	else \
		echo "$(COLOR_RED)❌ Composer not found in PATH$(COLOR_RESET)"; \
		echo "$(COLOR_YELLOW)Please install Composer or run manually:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)composer install$(COLOR_RESET)"; \
		echo ""; \
		echo "$(COLOR_YELLOW)Or use Docker instead:$(COLOR_RESET)"; \
		echo "  $(COLOR_CYAN)make docker-setup$(COLOR_RESET)"; \
		exit 1; \
	fi
	@echo "$(COLOR_GREEN)✅ Development environment ready!$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_CYAN)⚙️  NEXT STEPS:$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)1.$(COLOR_RESET) Edit $(COLOR_BOLD)config/config.env$(COLOR_RESET) - set DASHBOARD_SERVER_ID and DASHBOARD_API_KEY"
	@echo "$(COLOR_YELLOW)2.$(COLOR_RESET) Review $(COLOR_BOLD)config/apps.json$(COLOR_RESET), $(COLOR_BOLD)config/transmit.json$(COLOR_RESET), and $(COLOR_BOLD)config/databases.json$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)3.$(COLOR_RESET) Choose webserver config from $(COLOR_BOLD)docs/examples/webserver-configs/README.md$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BLUE)Or use Docker:$(COLOR_RESET) $(COLOR_CYAN)make docker-setup$(COLOR_RESET) then $(COLOR_CYAN)make dev-start$(COLOR_RESET)"

# Install Composer dependencies and git hooks
install-hooks:
	@echo "$(COLOR_BLUE)🪝 Installing git hooks...$(COLOR_RESET)"
	@if [ -f .githooks/pre-commit ]; then \
		ln -sf ../../.githooks/pre-commit .git/hooks/pre-commit; \
		chmod +x .git/hooks/pre-commit; \
		echo "$(COLOR_GREEN)✅ Git pre-commit hook installed$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)⚠️  .githooks/pre-commit not found, skipping$(COLOR_RESET)"; \
	fi


# ============================================
# Testing
# ============================================

# Run tests with PHPUnit (in Docker container)
test:
	@if docker-compose ps mata-web | grep -q "Up"; then \
		docker-compose exec mata-web vendor/bin/phpunit; \
	else \
		echo "$(COLOR_RED)❌ Docker containers not running. Start with: make dev-start$(COLOR_RESET)"; \
		exit 1; \
	fi

# Run tests with coverage (in Docker container)
test-coverage:
	docker-compose exec mata-web vendor/bin/phpunit --coverage-html coverage

# ============================================
# Docker Targets
# ============================================

## Setup & Installation

# First-time Docker development environment setup
docker-setup:
	@echo "$(COLOR_BLUE)🐳 Setting up Docker environment...$(COLOR_RESET)"
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo "$(COLOR_GREEN)✅ Created .env (Docker infrastructure settings)$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)⚠️  .env already exists, skipping$(COLOR_RESET)"; \
	fi
	@$(MAKE) bootstrap-config
	@if [ -d temp ]; then \
		if [ ! -w temp ]; then \
			echo "$(COLOR_YELLOW)⚠️  temp exists but is not writable; skipping temp/cache creation$(COLOR_RESET)"; \
		else \
			mkdir -p temp/cache; \
		fi; \
	else \
		if mkdir -p temp/cache 2>/dev/null; then \
			:; \
		else \
			echo "$(COLOR_YELLOW)⚠️  Could not create temp/cache; set TEMP_DIR in config/config.env or create manually$(COLOR_RESET)"; \
		fi; \
	fi
	@echo ""
	@echo "$(COLOR_CYAN)🔑 Dashboard API Key$(COLOR_RESET)"
	@read -p "$(COLOR_CYAN)Paste DASHBOARD_API_KEY from your dashboard (or press Enter to skip): $(COLOR_RESET)" dash_key; \
	if [ -n "$$dash_key" ]; then \
		tmpfile=$$(mktemp); \
		awk -v key="$$dash_key" 'BEGIN{found=0} \
			/^DASHBOARD_API_KEY=/{print "DASHBOARD_API_KEY=" key; found=1; next} \
			{print} \
			END{if(!found) print "DASHBOARD_API_KEY=" key}' \
			config/config.env > $$tmpfile; \
		mv $$tmpfile config/config.env; \
		chmod 600 config/config.env; \
		echo "$(COLOR_GREEN)✅ Updated DASHBOARD_API_KEY in config/config.env$(COLOR_RESET)"; \
		if docker-compose ps mata-web 2>/dev/null | grep -q "Up"; then \
			echo "$(COLOR_YELLOW)⚠️  Containers are already running. Env files are only read at container creation — run '$(COLOR_CYAN)make dev-recreate$(COLOR_RESET)$(COLOR_YELLOW)' to apply the new key.$(COLOR_RESET)"; \
		fi; \
	else \
		echo "$(COLOR_YELLOW)⚠️  Skipped. Set DASHBOARD_API_KEY manually in config/config.env$(COLOR_RESET)"; \
	fi
	@echo ""
	@echo "$(COLOR_GREEN)✅ Docker environment ready!$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_CYAN)⚙️  NEXT STEPS:$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)1.$(COLOR_RESET) Edit $(COLOR_BOLD)config/config.env$(COLOR_RESET) - set DASHBOARD_SERVER_ID and DASHBOARD_API_KEY"
	@echo "$(COLOR_YELLOW)2.$(COLOR_RESET) Review $(COLOR_BOLD)config/apps.json$(COLOR_RESET), $(COLOR_BOLD)config/transmit.json$(COLOR_RESET), and $(COLOR_BOLD)config/databases.json$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)3.$(COLOR_RESET) Start containers: $(COLOR_CYAN)make dev-start$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)4.$(COLOR_RESET) Check health at $(COLOR_CYAN)http://localhost:3310/health$(COLOR_RESET)"

# Create the external network shared with MATA Dashboard if neither project has yet
docker-network:
	@docker network inspect mata-network >/dev/null 2>&1 || docker network create mata-network >/dev/null

## Development (Docker)

# Start development environment (auto-runs docker-setup if needed)
dev-start: docker-network
	@echo "$(COLOR_BLUE)🚀 Starting docker development environment...$(COLOR_RESET)"
	@# Check if setup is needed
	@if [ ! -f .env ] || [ ! -f config/config.env ] || [ ! -f config/apps.json ] || [ ! -f config/transmit.json ] || [ ! -f config/databases.json ]; then \
		echo "$(COLOR_YELLOW)⚠️  Setup files missing. Running docker-setup first...$(COLOR_RESET)"; \
		echo ""; \
		$(MAKE) docker-setup; \
		echo ""; \
	fi
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
	@echo ""
	@echo "$(COLOR_GREEN)✅ Development environment running!$(COLOR_RESET)"
	@# Read actual port from .env file or use default
	@PORT=$$(grep -E '^WEB_PORT=' .env 2>/dev/null | cut -d '=' -f2 || echo "3310"); \
	echo "$(COLOR_CYAN)🌐 API: http://localhost:$$PORT$(COLOR_RESET)"; \
	echo "$(COLOR_CYAN)🩺 Health: http://localhost:$$PORT/health$(COLOR_RESET)"

# Start dev environment and follow logs
dev-logs: docker-network
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml logs -f

# Stop development environment
dev-stop:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down

# Restart development environment
dev-restart:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml restart

# Recreate development containers to reload env files
dev-recreate: docker-network
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d --force-recreate --no-build

# Rebuild development containers from scratch
dev-rebuild: docker-network
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml build --no-cache
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

## Production

# Deploy production environment
prod-start: docker-network
	@echo "$(COLOR_BLUE)🚀 Deploying production environment...$(COLOR_RESET)"
	@if [ ! -f .env ]; then \
		echo "$(COLOR_RED)❌ ERROR: .env file required for production$(COLOR_RESET)"; \
		exit 1; \
	fi
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
	@echo "$(COLOR_GREEN)✅ Production deployed!$(COLOR_RESET)"

# Stop production environment
prod-stop:
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml down

# Rebuild production from scratch
prod-rebuild: docker-network
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d

## Logs & Monitoring


# Follow all container logs
logs:
	docker-compose logs -f

# Follow web container logs only
logs-web:
	docker-compose logs -f mata-web

# Follow the PHP error log
logs-php:
	docker-compose exec -u www-data mata-web tail -fn 500 logs/php-error.log

# Follow the app log
logs-app:
	docker-compose exec -u www-data mata-web tail -fn 500 logs/app.log
	
# Show container status
ps:
	docker-compose ps

## Shell Access

# Access web container shell
shell:
	docker-compose exec mata-web bash

# Access web container as www-data user
shell-www:
	docker-compose exec -u www-data mata-web bash

## CLI

# Run a bin/mata-node command as www-data in the running container, e.g.
# make mata ARGS="log test shop". Without ARGS it lists the commands. make exits 2 for
# any failure, so scripts read the warning/error distinction from --json.
mata:
	@if docker-compose ps mata-web | grep -q "Up"; then \
		tty=-T; [ -t 0 ] && [ -t 1 ] && tty=; \
		docker-compose exec $$tty -u www-data mata-web php bin/mata-node $(ARGS); \
	else \
		echo "$(COLOR_RED)❌ Docker containers not running. Start with: make dev-start or make prod-start$(COLOR_RESET)"; \
		exit 1; \
	fi

## Composer (Docker)

# Install/update Composer dependencies in container
composer-install:
	@echo "$(COLOR_BLUE)📦 Installing Composer dependencies in container...$(COLOR_RESET)"
	@if docker-compose ps mata-web | grep -q "Up"; then \
		docker-compose exec mata-web composer install --no-interaction --optimize-autoloader; \
		echo "$(COLOR_GREEN)✅ Composer dependencies installed$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_RED)❌ Docker containers not running. Start with: make dev-start$(COLOR_RESET)"; \
		exit 1; \
	fi

# Update Composer dependencies in container
composer-update:
	@echo "$(COLOR_BLUE)📦 Updating Composer dependencies in container...$(COLOR_RESET)"
	@if docker-compose ps mata-web | grep -q "Up"; then \
		docker-compose exec mata-web composer update --no-interaction --optimize-autoloader; \
		echo "$(COLOR_GREEN)✅ Composer dependencies updated$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_RED)❌ Docker containers not running. Start with: make dev-start$(COLOR_RESET)"; \
		exit 1; \
	fi

# Clear and rebuild vendor (delete named volume and reinstall)
composer-rebuild: docker-network
	@echo "$(COLOR_YELLOW)🧹 Clearing vendor volume and rebuilding...$(COLOR_RESET)"
	@read -p "This will delete the vendor-data volume. Continue? [y/N]: " -n 1 -r; \
	echo; \
	if [ "$$REPLY" = "y" ] || [ "$$REPLY" = "Y" ]; then \
		docker-compose -f docker-compose.yml -f docker-compose.dev.yml down; \
		docker volume rm mata-node_vendor-data 2>/dev/null || true; \
		docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d; \
		echo "$(COLOR_GREEN)✅ Vendor will be reinstalled on container startup$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)Cancelled$(COLOR_RESET)"; \
	fi


## Cleanup

# Stop and remove all containers, networks
down:
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml down

# Remove everything including volumes (WARNING: deletes data!)
clean:
	@echo "$(COLOR_RED)⚠️  This will delete all containers, volumes, and database data!$(COLOR_RESET)"
	@read -p "Are you sure? [y/N]: " -n 1 -r; \
	echo; \
	if [ "$$REPLY" = "y" ] || [ "$$REPLY" = "Y" ]; then \
		docker-compose -f docker-compose.yml -f docker-compose.dev.yml down -v; \
		docker-compose -f docker-compose.yml -f docker-compose.prod.yml down -v; \
		echo "$(COLOR_GREEN)✅ Cleaned up$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)Cancelled$(COLOR_RESET)"; \
	fi

# Docker cleanup for this project only (safe - won't affect other containers)
docker-clean:
	@echo "$(COLOR_YELLOW)🧹 Cleaning up mata-node Docker resources...$(COLOR_RESET)"
	@echo "$(COLOR_CYAN)Stopping and removing containers...$(COLOR_RESET)"
	@docker-compose -f docker-compose.yml -f docker-compose.dev.yml down -v 2>/dev/null || true
	@docker-compose -f docker-compose.yml -f docker-compose.prod.yml down -v 2>/dev/null || true
	@echo "$(COLOR_CYAN)Removing mata-node images...$(COLOR_RESET)"
	@docker images --filter "reference=mata-node*" -q | xargs -r docker rmi -f 2>/dev/null || true
	@docker images --filter "reference=${COMPOSE_PROJECT_NAME:-mata-node}*" -q | xargs -r docker rmi -f 2>/dev/null || true
	@echo "$(COLOR_CYAN)Removing orphaned containers...$(COLOR_RESET)"
	@docker ps -a --filter "name=mata-" -q | xargs -r docker rm -f 2>/dev/null || true
	@echo "$(COLOR_GREEN)✅ mata-node Docker resources cleaned$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)💡 Other Docker projects are unaffected$(COLOR_RESET)"

# System-wide Docker cleanup (WARNING: affects ALL Docker resources)
docker-clean-all:
	@echo "$(COLOR_RED)⚠️  This will clean up ALL Docker resources (not just mata-node)!$(COLOR_RESET)"
	@read -p "Are you sure? [y/N]: " -n 1 -r; \
	echo; \
	if [ "$$REPLY" = "y" ] || [ "$$REPLY" = "Y" ]; then \
		docker system prune -a; \
		echo "$(COLOR_GREEN)✅ System-wide cleanup complete$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)Cancelled$(COLOR_RESET)"; \
	fi

## Help

# Show available Docker commands
docker-help:
	@echo "$(COLOR_BOLD)$(COLOR_CYAN)Docker Development Commands:$(COLOR_RESET)"
	@echo ""
	@echo "$(COLOR_BOLD)Setup:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make docker-setup$(COLOR_RESET)    - First-time environment setup (optional)"
	@echo ""
	@echo "$(COLOR_BOLD)Development:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make dev-start$(COLOR_RESET)             - Start development environment (auto-setup if needed)"
	@echo "  $(COLOR_GREEN)make dev-logs$(COLOR_RESET)        - Start dev and follow logs"
	@echo "  $(COLOR_GREEN)make dev-stop$(COLOR_RESET)        - Stop development environment"
	@echo "  $(COLOR_GREEN)make dev-restart$(COLOR_RESET)     - Restart development containers"
	@echo "  $(COLOR_GREEN)make dev-recreate$(COLOR_RESET)    - Recreate containers (reload env files)"
	@echo "  $(COLOR_GREEN)make dev-rebuild$(COLOR_RESET)     - Rebuild from scratch"
	@echo ""
	@echo "$(COLOR_BOLD)Production:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make prod$(COLOR_RESET)            - Deploy production environment"
	@echo "  $(COLOR_GREEN)make prod-stop$(COLOR_RESET)       - Stop production"
	@echo "  $(COLOR_GREEN)make prod-rebuild$(COLOR_RESET)    - Rebuild production"
	@echo ""
	@echo "$(COLOR_BOLD)Logs & Monitoring:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make logs$(COLOR_RESET)            - Follow all logs"
	@echo "  $(COLOR_GREEN)make logs-web$(COLOR_RESET)        - Follow web container logs"
	@echo "  $(COLOR_GREEN)make ps$(COLOR_RESET)              - Show container status"
	@echo ""
	@echo "$(COLOR_BOLD)Shell Access:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make shell$(COLOR_RESET)           - Access web container"
	@echo "  $(COLOR_GREEN)make shell-www$(COLOR_RESET)       - Access as www-data user"
	@echo "  $(COLOR_GREEN)make mata ARGS=…$(COLOR_RESET)     - Run a bin/mata-node command as www-data"
	@echo ""
	@echo "$(COLOR_BOLD)Composer:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make composer-install$(COLOR_RESET)  - Install dependencies in container"
	@echo "  $(COLOR_GREEN)make composer-update$(COLOR_RESET)   - Update dependencies in container"
	@echo "  $(COLOR_GREEN)make composer-rebuild$(COLOR_RESET)  - Clear vendor volume and rebuild"
	@echo ""
	@echo "$(COLOR_BOLD)Cleanup:$(COLOR_RESET)"
	@echo "  $(COLOR_GREEN)make down$(COLOR_RESET)            - Stop all containers"
	@echo "  $(COLOR_RED)make clean$(COLOR_RESET)           - Remove everything (including data!)"
	@echo "  $(COLOR_YELLOW)make docker-clean$(COLOR_RESET)    - Clean mata-node Docker resources only"
	@echo "  $(COLOR_RED)make docker-clean-all$(COLOR_RESET) - System-wide Docker cleanup (ALL projects!)"

.PHONY: help bootstrap-config setup install-hooks \
	test test-coverage \
	bootstrap \
	docker-setup docker-network dev-start dev-logs dev-stop dev-restart dev-recreate dev-rebuild \
	prod-start prod-stop prod-rebuild \
	logs logs-web logs-php logs-app ps \
	shell shell-www mata \
	composer-install composer-update composer-rebuild \
	down clean docker-clean docker-clean-all docker-help
