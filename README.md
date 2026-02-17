# Everyday Accounts Blog

Statamic 5 blog on Laravel 12, migrated from WordPress. Dual-architecture deployment: a private CMS on a local VM and a static public site on Cloudflare Pages.

## Architecture

```
                    Local VM                              Cloudflare
         ┌──────────────────────────┐        ┌──────────────────────────┐
         │  Traefik (reverse proxy) │        │  Cloudflare Pages        │
         │  Let's Encrypt TLS       │        │  everydayaccountsblog.com│
         │          │               │        │  (static HTML/CSS/JS)    │
         │  Statamic CMS (FrankenPHP│)       └──────────────────────────┘
         │  admin.everydayaccounts  │        ┌──────────────────────────┐
         │       blog.com           │        │  Cloudflare R2           │
         │  (content editing)       │        │  images.everydayaccounts │
         └──────────────────────────┘        │       blog.com           │
                    │                        │  (media assets)          │
                    │ GitHub API               └──────────────────────────┘
                    ▼                                    ▲
              ┌──────────┐   GitHub Actions              │
              │  GitHub  │──────────────────►  ssg:generate + deploy
              └──────────┘
```

**Content workflow:** Edit in Statamic CP → queued job syncs flat files via GitHub API → GitHub Actions builds static site → deploys to Cloudflare Pages.

## Content Sync (Custom GitHub Integration)

Since Statamic's built-in git automation requires a Pro license, we built a custom GitHub sync that uses the GitHub REST API to push content changes without needing git installed on the CMS server.

### How It Works

**Event Listener** (`ContentGitSubscriber`) — Listens for all Statamic content Saved/Deleted events:
- Accumulates changes in cache with commit messages and authenticated user info
- Dispatches a `SyncContentToGitHub` job with a 2-minute delay to batch rapid edits

**Sync Job** (`SyncContentToGitHub`) — Runs uniquely (overlapping jobs collapse):
1. Fetches the current branch tree from GitHub (paths + blob SHAs only, no file downloads)
2. Compares local file SHAs against remote SHAs using git's blob hash algorithm
3. Detects additions, modifications, and deletions
4. Creates an atomic commit via GitHub API if changes exist

**GitHub API Service** (`GitHubApiService`) — Commits entirely through REST API:
- Creates blobs for changed files
- Builds a new tree
- Creates commit object with proper author attribution
- Updates the branch ref

No local git binary required. All SHA comparison is in-memory.

### Configuration

Set these in your production `.env` (CMS only):

```bash
GITHUB_SYNC_ENABLED=true
GITHUB_SYNC_REPOSITORY=owner/repo
GITHUB_SYNC_BRANCH=main
GITHUB_SYNC_TOKEN=ghp_xxx  # needs repo write access
GITHUB_SYNC_DISPATCH_DELAY=2  # minutes to batch edits
GITHUB_SYNC_COMMITTER_NAME="Statamic CMS"
GITHUB_SYNC_COMMITTER_EMAIL="cms@example.com"
```

Tracked paths: `content/`, `resources/blueprints/`, `resources/fieldsets/`, `resources/forms/`, `resources/users/`.

### Deployment Flow

```
CP edit → event → cache → job (2min delay)
  → diff local vs GitHub tree via API
  → commit to main via GitHub API
  → GitHub Actions deploys
```

The `cf-pages` branch (which holds generated static HTML) only exists on GitHub — it's created/updated by the GitHub Actions runner, never locally.

## Development

```bash
# Start all dev servers (artisan serve + queue + pail + vite)
composer dev

# Run tests
composer test

# Generate static site
php please ssg:generate
```

## CMS Deployment (VM)

The CMS runs on a local VM via Docker Swarm with Traefik and Let's Encrypt TLS.

### Prerequisites

- Docker initialized in Swarm mode on the VM
- `admin.everydayaccountsblog.com` DNS A record pointing to the VM's LAN IP
- Cloudflare API token with `Zone > Zone > Read` and `Zone > DNS > Edit` permissions for `everydayaccountsblog.com`

### 1. Create Docker secrets

Create a `.env.production` file with your production environment variables (`APP_URL` must use `https://`), then store both secrets:

```bash
# App environment
docker --context emilyblog secret create env_file .env.production

# Cloudflare API token (for Let's Encrypt DNS challenge)
echo -n "your-cloudflare-api-token" | docker --context emilyblog secret create cf_api_token -
```

TLS certificates are provisioned via Let's Encrypt DNS challenge through the Cloudflare API — the VM does not need to be publicly reachable.

### 2. Build and transfer the Docker image

Build and transfer run against the **local** Docker daemon (no `--context`). The SSH pipe handles getting the image to the VM.

```bash
# Build the production image locally
docker build --target production -t statamic-cms:latest .

# Transfer to VM (no registry needed)
docker save statamic-cms:latest | gzip | ssh dabernathy@192.168.100.151 'gunzip | docker load'
```

### 3. Deploy the stack

All `docker` commands that target the VM use `--context emilyblog`.

```bash
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic
```

### Verify

```bash
docker --context emilyblog stack services statamic
docker --context emilyblog stack ps statamic
```

### Updating

Run `./deploy.sh` to build, transfer, and deploy in one step. The script forces a service restart because Swarm won't pick up a new local image under the same `latest` tag without it.

Docker secrets are immutable. To rotate one, detach it, remove/recreate, then redeploy the stack:

```bash
# Update .env
docker --context emilyblog service update --secret-rm env_file statamic_statamic
docker --context emilyblog secret rm env_file
docker --context emilyblog secret create env_file .env.production
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic

# Update Cloudflare token
docker --context emilyblog service update --secret-rm cf_api_token statamic_traefik
docker --context emilyblog secret rm cf_api_token
echo -n "new-token" | docker --context emilyblog secret create cf_api_token -
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic
```

## Static Site Deployment

Handled automatically by GitHub Actions (`.github/workflows/deploy.yml`) on push to `main`:

1. Merges `main` into `cf-pages` branch
2. Detects changes (incremental vs full rebuild):
   - **Incremental:** Only article markdown changed → regenerates affected URLs, archives, feeds, sitemap
   - **Full:** Templates, config, or code changed → regenerates entire site
3. Builds frontend assets (`npm run build`)
4. Runs `php please ssg:generate` with detected URLs (or `--workers=2` for full)
5. Commits static HTML to `cf-pages` branch
6. Cloudflare Pages deploys from `cf-pages`

The `cf-pages` branch holds only generated HTML and never exists locally — it's managed entirely by CI.

## Troubleshooting

```bash
# Service logs
docker --context emilyblog service logs statamic_statamic
docker --context emilyblog service logs statamic_traefik

# Verify the .env secret is mounted
docker --context emilyblog exec $(docker ps -q -f name=statamic_statamic) ls -la /app/.env
```
