#!/bin/bash
set -e

# Development entrypoint script
# Runs Apache as the host repo owner so bind mounts do not need broad chmod/chown.

echo "🔧 Setting up development environment..."

HOST_UID="${HOST_UID:-$(stat -c '%u' /var/www/html)}"
HOST_GID="${HOST_GID:-$(stat -c '%g' /var/www/html)}"

if [ "$HOST_UID" != "0" ]; then
    echo "👤 Mapping www-data to host uid:gid ${HOST_UID}:${HOST_GID}"
    groupmod -o -g "$HOST_GID" www-data
    usermod -o -u "$HOST_UID" -g www-data www-data
fi

# Ensure runtime directories exist. If created by root, hand them to the host owner.
mkdir -p \
    /var/www/html/temp/cache \
    /var/www/html/logs \
    /var/www/html/config
chown -R "$HOST_UID:$HOST_GID" /var/www/html/temp /var/www/html/logs
chown "$HOST_UID:$HOST_GID" /var/www/html/config
chmod -R u+rwX,go-rwx /var/www/html/temp /var/www/html/logs

# Copy config files from examples if they don't exist
echo "📋 Checking for config files..."
for file in config.env mail.config.env; do
    if [ ! -f "/var/www/html/config/$file" ]; then
        if [ -f "/var/www/html/config/example/${file}.example" ]; then
            cp "/var/www/html/config/example/${file}.example" "/var/www/html/config/$file"
            chown "$HOST_UID:$HOST_GID" "/var/www/html/config/$file"
            chmod 600 "/var/www/html/config/$file"
            echo "✅ Created config/$file from example"
        else
            echo "⚠️  Warning: config/example/${file}.example not found"
        fi
    fi
done


# If vendor autoload doesn't exist, run composer install
if [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

echo "✅ Development environment ready!"

# Start cron (www-data crontab will run) and Apache
umask 0077
exec sh -c "cron && apache2-foreground"
