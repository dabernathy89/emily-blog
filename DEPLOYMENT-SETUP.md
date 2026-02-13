# Content Sync Setup

This guide covers the automated content-to-GitHub pipeline: Statamic Git Integration, the queue worker, and the SQLite database that supports it.

For general CMS deployment (building, transferring, and deploying the Docker image), see the [CMS Deployment section in README.md](README.md#cms-deployment-vm).

## What `docker-entrypoint.sh` Automates

On container startup, the entrypoint script handles:

1. **Git Authentication** — reads `github_token` Docker secret, configures git credentials
2. **Git Repository** — initializes repo and sets remote if `.git` doesn't exist
3. **SQLite Database** — creates `database/production.sqlite` if it doesn't exist
4. **Migrations** — runs `php artisan migrate --force`
5. **Queue Worker** — starts `php artisan queue:work` in the background
6. **Graceful Shutdown** — traps SIGTERM/SIGINT to stop the queue worker cleanly

## Prerequisites

Before deploying with content sync enabled, you need one additional Docker secret beyond what's described in the README.

### Create a GitHub Personal Access Token

**Fine-Grained Token (Recommended):**

1. Go to https://github.com/settings/tokens?type=beta
2. Generate new token:
   - **Token name**: `Statamic CMS Production`
   - **Expiration**: 90 days (set a calendar reminder to rotate)
   - **Repository access**: Only select repositories > `dabernathy89/emily-blog`
   - **Permissions**: Contents — Read and write
3. Copy the token immediately (`github_pat_...`)

**Classic Token (Alternative):**

1. Go to https://github.com/settings/tokens
2. Generate new token (classic):
   - **Scopes**: `repo`
   - **Expiration**: 90 days
3. Copy the token (`ghp_...`)

### Create the Docker Secret

```bash
echo -n "YOUR_GITHUB_TOKEN" | docker --context emilyblog secret create github_token -
```

Then rebuild and redeploy per the README.

## Content Update Flow

```
Editor saves in Statamic CP
        │
        ▼
Statamic fires Saved event
        │
        ▼
Git Integration queues commit job (database queue)
        │
        ▼
Queue worker commits + pushes to GitHub
        │
        ▼
GitHub Actions triggers (push to main)
        │
        ▼
Incremental or full SSG build
        │
        ▼
Deploy to Cloudflare Pages
```

## Verify Content Sync

After deploying:

```bash
docker --context emilyblog service logs statamic_statamic -f
```

You should see:
- "Configuring git authentication..."
- "Running database migrations..."
- "Queue worker started with PID: ..."
- "Starting FrankenPHP..."

Test by editing an article in the CP and checking `dabernathy89/emily-blog` for a new commit.

## Rotating the GitHub Token

Docker secrets are immutable. To rotate:

```bash
# Detach secret from service
docker --context emilyblog service update --secret-rm github_token statamic_statamic

# Remove and recreate
docker --context emilyblog secret rm github_token
echo -n "NEW_TOKEN" | docker --context emilyblog secret create github_token -

# Redeploy
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic
```

If the token expires, content editing in the CP still works — only the git push will fail silently. Check logs:

```bash
docker --context emilyblog service logs statamic_statamic 2>&1 | grep -i git
```

## Troubleshooting

### Queue jobs not processing

```bash
# Check if queue worker is running
docker --context emilyblog exec $(docker --context emilyblog ps -q -f name=statamic_statamic) ps aux | grep queue
```

### Database file permissions

The SQLite database is in a Docker volume (`statamic-database`). If migrations fail, check ownership:

```bash
docker --context emilyblog exec $(docker --context emilyblog ps -q -f name=statamic_statamic) ls -la /app/database/
```

The `www-data` user (uid 33) should own the file and directory.
