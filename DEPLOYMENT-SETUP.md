# Content Sync Setup

This guide covers the automated content-to-GitHub pipeline: the GitHub API sync service, the queue worker, and the SQLite database that supports it.

For general CMS deployment (building, transferring, and deploying the Docker image), see the [CMS Deployment section in README.md](README.md#cms-deployment-vm).

## How It Works

Content sync uses the GitHub Git Data API instead of the git CLI. No git binary or `.git` directory is needed in production. When content changes in the CP, a queued job compares local files against the remote tree and commits only the differences via the API.

## What `docker-entrypoint.sh` Automates

On container startup, the entrypoint script handles:

1. **SQLite Database** — creates `database/production.sqlite` if it doesn't exist
2. **Migrations** — runs `php artisan migrate --force`
3. **Queue Worker** — starts `php artisan queue:work` in the background
4. **Graceful Shutdown** — traps SIGTERM/SIGINT to stop the queue worker cleanly

## Prerequisites

Add the following to your production `.env`:

```
GITHUB_SYNC_ENABLED=true
GITHUB_SYNC_REPOSITORY=dabernathy89/emily-blog
GITHUB_SYNC_BRANCH=main
GITHUB_SYNC_TOKEN=ghp_your_token_here
```

### Create a GitHub Personal Access Token

**Fine-Grained Token (Recommended):**

1. Go to https://github.com/settings/tokens?type=beta
2. Generate new token:
   - **Token name**: `Statamic CMS Production`
   - **Expiration**: 90 days (set a calendar reminder to rotate)
   - **Repository access**: Only select repositories > `dabernathy89/emily-blog`
   - **Permissions**: Contents — Read and write
3. Copy the token immediately (`github_pat_...`)

## Content Update Flow

```
Editor saves in Statamic CP
        │
        ▼
Statamic fires content event (e.g. EntrySaved)
        │
        ▼
ContentGitSubscriber appends to cache, dispatches delayed job (2 min)
        │
        ▼
SyncContentToGitHub job compares local files vs remote tree
        │
        ▼
Creates atomic commit via GitHub Git Data API
        │
        ▼
GitHub Actions triggers (push to main)
        │
        ▼
Deploy to Cloudflare Pages
```

## Deploying

Run `./deploy.sh` to build, transfer, and deploy. The script uses `docker service update --force` because Swarm won't restart a service when the image tag (`latest`) hasn't changed — local images have no registry digest to compare, so Swarm needs the explicit force flag to pick up the new image.

## Verify Content Sync

After deploying:

```bash
docker --context emilyblog service logs statamic_statamic --since 5m
```

Test by editing an article in the CP. After the 2-minute dispatch delay, check `dabernathy89/emily-blog` for a new commit.

## Rotating the GitHub Token

Update `GITHUB_SYNC_TOKEN` in your production `.env` and redeploy.

If the token expires, content editing in the CP still works — only the GitHub sync will fail. Check logs:

```bash
docker --context emilyblog service logs statamic_statamic --since 30m 2>&1 | grep -i "github\|sync"
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
