#!/bin/sh
set -e

echo "Starting Statamic CMS container..."

# Configure git if credentials are provided
if [ -f "/run/secrets/github_token" ]; then
    echo "Configuring git authentication..."
    GITHUB_TOKEN=$(cat /run/secrets/github_token)
    git config --global credential.helper store
    echo "https://${GITHUB_TOKEN}@github.com" > ~/.git-credentials
    git config --global user.name "${STATAMIC_GIT_USER_NAME:-Statamic CMS}"
    git config --global user.email "${STATAMIC_GIT_USER_EMAIL:-cms@example.com}"
elif [ -n "$GITHUB_TOKEN" ]; then
    echo "Configuring git authentication from env..."
    git config --global credential.helper store
    echo "https://${GITHUB_TOKEN}@github.com" > ~/.git-credentials
    git config --global user.name "${STATAMIC_GIT_USER_NAME:-Statamic CMS}"
    git config --global user.email "${STATAMIC_GIT_USER_EMAIL:-cms@example.com}"
fi

# Initialize git repo if not already initialized
if [ ! -d "/app/.git" ]; then
    echo "Initializing git repository..."
    cd /app
    git init
    git remote add origin "${GITHUB_REPO_URL:-}" || true
fi

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

# Run database migrations
echo "Running database migrations..."
php artisan migrate --force --no-interaction

# Create queue jobs table if not exists
echo "Ensuring queue table exists..."
php artisan queue:table --no-interaction || true
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

# Trap termination signals
trap shutdown SIGTERM SIGINT

# Start FrankenPHP
echo "Starting FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile
