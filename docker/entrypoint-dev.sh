#!/bin/bash
set -e

# Development entrypoint script
# Fixes file permissions for mounted volumes to allow www-data to write

echo "🔧 Setting up development environment..."

# Ensure directories exist and have correct permissions
mkdir -p \
    /var/www/html/temp/cache \
    /var/www/html/logs \
    /var/www/html/config

# Copy config files from examples if they don't exist
echo "📋 Checking for config files..."
for file in config.env mail.config.env; do
    if [ ! -f "/var/www/html/config/$file" ]; then
        if [ -f "/var/www/html/config/example/${file}.example" ]; then
            cp "/var/www/html/config/example/${file}.example" "/var/www/html/config/$file"
            echo "✅ Created config/$file from example"
        else
            echo "⚠️  Warning: config/example/${file}.example not found"
        fi
    fi
done

# Fix permissions for ALL mounted files (source code, vendor, etc.)
# In dev mode, entire /var/www/html is mounted from host with host user ownership
# We need to ensure www-data can read everything and write to specific dirs
echo "📁 Fixing permissions for mounted source code..."

# Make all files readable by www-data (keep host user as owner for editing)
chmod -R a+rX /var/www/html

# Fix permissions for writable directories without taking ownership from the host user.
# Chowning bind mounts to www-data breaks later Docker builds: the host user
# cannot read temp/logs and Docker cannot package the build context.
echo "📁 Setting up writable directories..."
chmod -R a+rwX /var/www/html/temp /var/www/html/logs
chmod -R a+rX /var/www/html/config

# If vendor autoload doesn't exist, run composer install
if [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

echo "✅ Development environment ready!"

# Start cron (www-data crontab will run) and Apache
exec sh -c "cron && apache2-foreground"
