#!/bin/sh
set -e

echo "Starting Statamic CMS container..."

# Create database directory if it doesn't exist
if [ ! -d "/app/database" ]; then
    echo "Creating database directory..."
    mkdir -p /app/database
fi

# Create SQLite database file if it doesn't exist
if [ ! -f "/app/database/production.sqlite" ]; then
    echo "Creating SQLite database file..."
    touch /app/database/production.sqlite
fi

# Cache config (.env is mounted as a Docker secret at runtime)
echo "Caching configuration..."
php artisan config:cache

# Run database migrations
echo "Running database migrations..."
php artisan migrate --force --no-interaction

# Start queue worker in background
echo "Starting queue worker..."
php artisan queue:work --daemon --tries=3 --timeout=90 --sleep=3 --max-jobs=1000 &

# Store queue worker PID
QUEUE_PID=$!
echo "Queue worker started with PID: $QUEUE_PID"

# Function to handle shutdown gracefully
shutdown() {
    echo "Shutting down gracefully..."
    kill -TERM $QUEUE_PID 2>/dev/null || true
    wait $QUEUE_PID 2>/dev/null || true
    exit 0
}

# Trap termination signals (POSIX sh uses TERM/INT, not SIGTERM/SIGINT)
trap shutdown TERM INT

# Start FrankenPHP
echo "Starting FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile
