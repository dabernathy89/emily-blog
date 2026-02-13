# Automated Deployment Setup

This guide covers the automated SQLite database creation, queue worker startup, and content sync to GitHub.

## What's Automated

The `docker-entrypoint.sh` script now handles:

1. **SQLite Database**: Creates `/app/database/production.sqlite` if it doesn't exist
2. **Migrations**: Runs all database migrations on startup
3. **Queue Worker**: Starts `php artisan queue:work` in the background
4. **Git Authentication**: Configures git credentials for pushing to GitHub
5. **Graceful Shutdown**: Properly terminates queue worker on container stop

## Deployment Checklist

### 1. Create GitHub Personal Access Token

#### Option A: Fine-Grained Token (Recommended)

1. Go to https://github.com/settings/tokens?type=beta
2. Click "Generate new token"
3. Configure the token:
   - **Token name**: `Statamic CMS Production`
   - **Expiration**: 90 days (or custom - see rotation section below)
   - **Description**: `Automated content pushes from Statamic CMS`
   - **Repository access**: Only select repositories → Choose `dabernathy89/emily-blog`
   - **Permissions**:
     - Repository permissions → Contents: **Read and write**
4. Click "Generate token"
5. **IMPORTANT**: Copy the token immediately (starts with `github_pat_...`)
   - You won't be able to see it again
   - Store it temporarily in a secure location

#### Option B: Classic Token (Legacy)

1. Go to https://github.com/settings/tokens
2. Click "Generate new token (classic)"
3. Configure:
   - **Note**: `Statamic CMS Production`
   - **Expiration**: 90 days
   - **Scopes**: Check `repo` (full control of private repositories)
4. Click "Generate token"
5. Copy the token (starts with `ghp_...`)

### 2. Create Docker Secrets

On your Docker Swarm server:

```bash
# Create the github_token secret
echo "YOUR_GITHUB_TOKEN_HERE" | docker secret create github_token -

# Update the env_file secret with your updated .env.production
docker secret create env_file .env.production

# Verify secrets were created
docker secret ls
```

**Note**: If `env_file` secret already exists, you'll need to remove the service first, remove the old secret, create a new one, then redeploy (see Token Rotation section).

### 3. Update Docker Stack Configuration

The `docker-stack-cms.yml` is already configured with:
- Repository: `https://github.com/dabernathy89/emily-blog.git`
- Secrets: `github_token` and `env_file` (mounts as `/app/.env`)
- Volume: `statamic-database` for SQLite persistence

### 4. Build and Deploy

```bash
# Build the production image
docker build --target production -t your-registry/statamic-cms:latest .

# Push to registry
docker push your-registry/statamic-cms:latest

# Update the image name in docker-stack.yml
# Then deploy the stack
docker stack deploy -c docker-stack-cms.yml statamic
```

### 5. Verify Setup

Check the container logs to confirm everything started:

```bash
docker service logs statamic_statamic -f
```

You should see:
- "Configuring git authentication..."
- "Running database migrations..."
- "Starting queue worker..."
- "Queue worker started with PID: ..."
- "Starting FrankenPHP..."

### 6. Test Content Sync

1. Log into your Statamic control panel
2. Edit an article and save it
3. Wait a few seconds for the queue to process
4. Check your GitHub repo for a new commit from "dabernathy89"

## How It Works

### Content Update Flow

1. Editor saves content in Statamic CP → Statamic fires `Saved` event
2. Statamic Git Integration queues a commit job → Job goes to `database` queue
3. Queue worker processes job → Commits changes with user's name/email
4. Git pushes to GitHub → Triggers GitHub Actions workflow
5. GitHub Actions detects changes → Runs incremental or full SSG build
6. GitHub Actions deploys to Cloudflare Pages → Site is updated

### Database Persistence

The SQLite database is stored in a Docker volume (`statamic-database`), so it persists across container restarts.

### Queue Worker Restart

The queue worker runs inside the container. If the container restarts, the entrypoint script automatically starts a new queue worker.

## Token Rotation

GitHub tokens should be rotated periodically for security. Here's how to rotate the token without downtime:

### Step 1: Generate New Token

Follow the [Create GitHub Personal Access Token](#1-create-github-personal-access-token) steps above to create a new token with the same permissions.

### Step 2: Update Docker Secret

Docker secrets are immutable, so you need to:

1. **Create new secret with different name**:
   ```bash
   echo "NEW_GITHUB_TOKEN_HERE" | docker secret create github_token_v2 -
   ```

2. **Update docker-stack-cms.yml** to use new secret:
   ```yaml
   secrets:
     - github_token_v2  # Changed from github_token
   ```

3. **Update docker-entrypoint.sh** to check for new secret name:
   ```bash
   if [ -f "/run/secrets/github_token_v2" ]; then
       GITHUB_TOKEN=$(cat /run/secrets/github_token_v2)
   ```

4. **Redeploy the stack**:
   ```bash
   docker stack deploy -c docker-stack-cms.yml statamic
   ```

5. **Remove old secret** (after verifying new one works):
   ```bash
   docker secret rm github_token
   ```

### Alternative: Rolling Update Without Changing Secret Name

If you want to keep the same secret name:

1. **Remove the service** (this will cause brief downtime):
   ```bash
   docker service rm statamic_statamic
   ```

2. **Remove the old secret**:
   ```bash
   docker secret rm github_token
   ```

3. **Create new secret with same name**:
   ```bash
   echo "NEW_GITHUB_TOKEN_HERE" | docker secret create github_token -
   ```

4. **Redeploy the stack**:
   ```bash
   docker stack deploy -c docker-stack-cms.yml statamic
   ```

### Token Expiration Monitoring

Set a calendar reminder 1 week before your token expires to rotate it proactively.

If the token expires:
- Content saves will still work in Statamic
- Git commits will fail silently (queued jobs will error)
- GitHub Actions won't trigger
- Check logs: `docker service logs statamic_statamic | grep -i "git push"`

## Troubleshooting

### Git push fails

Check the container logs for git errors. Common issues:
- GitHub token expired or has wrong permissions
- GitHub token secret not properly created

Fix by recreating the Docker secret:

```bash
docker secret rm github_token
echo "NEW_TOKEN" | docker secret create github_token -
docker service update --force statamic_statamic
```

### Queue jobs not processing

Check if the queue worker is running:

```bash
docker exec -it $(docker ps -q -f name=statamic_statamic) ps aux | grep queue
```

Check queue jobs table:

```bash
docker exec -it $(docker ps -q -f name=statamic_statamic) php artisan queue:monitor
```

### Database migrations fail

Check database file permissions:

```bash
docker exec -it $(docker ps -q -f name=statamic_statamic) ls -la /app/database/
```

The `www-data` user should own the database file and directory.

## Environment Variables

Key variables in `.env.production`:

```env
# Queue (database driver for persistence)
QUEUE_CONNECTION=database

# SQLite database
DB_CONNECTION=sqlite
DB_DATABASE=database/production.sqlite

# Statamic Git Integration
STATAMIC_GIT_ENABLED=true
STATAMIC_GIT_PUSH=true
STATAMIC_GIT_AUTOMATIC=true
STATAMIC_GIT_USER_NAME="dabernathy89"
STATAMIC_GIT_USER_EMAIL="dabernathy89@gmail.com"
```
