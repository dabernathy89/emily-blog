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
                    │ git push               └──────────────────────────┘
                    ▼                                    ▲
              ┌──────────┐   GitHub Actions              │
              │  GitHub  │──────────────────►  ssg:generate + deploy
              └──────────┘
```

**Content workflow:** Edit in Statamic CP → flat files committed to git → GitHub Actions builds static site → deploys to Cloudflare Pages.

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

All `docker` commands that target the VM use `--context emilyblog`. The stack file reads `CMS_DOMAIN` and `ACME_EMAIL` from `.env` automatically.

```bash
docker --context emilyblog stack deploy -c docker-stack-cms.yml statamic
```

### Verify

```bash
docker --context emilyblog stack services statamic
docker --context emilyblog stack ps statamic
```

### Updating

To deploy code/content changes, rebuild the image and repeat steps 2-3.

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

Handled automatically by GitHub Actions on push to `main`:

1. Builds frontend assets (`npm run build`)
2. Runs `php please ssg:generate`
3. Syncs build assets to Cloudflare R2
4. Deploys static HTML to Cloudflare Pages

## Troubleshooting

```bash
# Service logs
docker --context emilyblog service logs statamic_statamic
docker --context emilyblog service logs statamic_traefik

# Verify the .env secret is mounted
docker --context emilyblog exec $(docker ps -q -f name=statamic_statamic) ls -la /app/.env
```
