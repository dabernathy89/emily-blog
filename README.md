# Statamic Blog Docker Swarm Setup

This project sets up a complete blog migration from WordPress to Statamic using Docker Swarm, featuring:

- **Dynamic Statamic CMS** at `cms.yourdomain.com` (basic auth protected)
- **Static site** at `yourdomain.com` (generated on content updates)
- **Shared asset storage** via Docker volumes
- **Traefik reverse proxy** with automatic SSL
- **SQLite database** for content storage

## Prerequisites

- Docker Swarm initialized on your VPS
- Traefik running as a reverse proxy with the `traefik-public` network
- Domain names configured to point to your VPS

## Setup Instructions

### 1. Create Docker Secrets

Before deploying the stack, you only need to create the basic auth secret for CMS protection.

#### Basic Auth for CMS Protection

The CMS subdomain is protected with basic auth to prevent search engines from indexing duplicate content.

**Option 1: Using htpasswd (recommended)**

```bash
# Install htpasswd if not available
# Ubuntu/Debian: sudo apt-get install apache2-utils
# CentOS/RHEL: sudo yum install httpd-tools
# macOS: brew install httpd

# Generate the auth string (replace 'admin' and 'yourpassword' with your credentials)
htpasswd -nbB admin yourpassword

# Create the secret with the output from above
echo "admin:\$2y\$10\$your-generated-hash-here" | docker secret create basic_auth_password -
```

**Important Notes:**
- Always escape `# Statamic Blog Docker Swarm Setup

This project sets up a complete blog migration from WordPress to Statamic using Docker Swarm, featuring:

- **Dynamic Statamic CMS** at `cms.yourdomain.com` (basic auth protected)
- **Static site** at `yourdomain.com` (generated on content updates)
- **Shared asset storage** via Docker volumes
- **Traefik reverse proxy** with automatic SSL
- **SQLite database** for content storage

## Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Static Site   │    │  Dynamic CMS    │    │  Shared Volumes │
│ yourdomain.com  │    │ cms.yourdomain  │    │   - Database    │
│     (Nginx)     │    │   (Statamic)    │    │   - Assets      │
│                 │    │                 │    │   - Glide Cache │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
                         ┌─────────────────┐
                         │     Traefik     │
                         │ Reverse Proxy   │
                         │   + SSL/TLS     │
                         └─────────────────┘
```

## Prerequisites

- Docker Swarm initialized on your VPS
- Traefik running as a reverse proxy with the `traefik-public` network
- Domain names configured to point to your VPS

## Setup Instructions

### 1. Create Docker Secrets

Before deploying the stack, you only need to create the basic auth secret for CMS protection.

#### Basic Auth for CMS Protection

The CMS subdomain is protected with basic auth to prevent search engines from indexing duplicate content.

 characters with `\# Statamic Blog Docker Swarm Setup

This project sets up a complete blog migration from WordPress to Statamic using Docker Swarm, featuring:

- **Dynamic Statamic CMS** at `cms.yourdomain.com` (basic auth protected)
- **Static site** at `yourdomain.com` (generated on content updates)
- **Shared asset storage** via Docker volumes
- **Traefik reverse proxy** with automatic SSL
- **SQLite database** for content storage

## Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Static Site   │    │  Dynamic CMS    │    │  Shared Volumes │
│ yourdomain.com  │    │ cms.yourdomain  │    │   - Database    │
│     (Nginx)     │    │   (Statamic)    │    │   - Assets      │
│                 │    │                 │    │   - Glide Cache │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
                         ┌─────────────────┐
                         │     Traefik     │
                         │ Reverse Proxy   │
                         │   + SSL/TLS     │
                         └─────────────────┘
```

## Prerequisites

- Docker Swarm initialized on your VPS
- Traefik running as a reverse proxy with the `traefik-public` network
- Domain names configured to point to your VPS

## Setup Instructions

### 1. Create Docker Secrets

Before deploying the stack, you only need to create the basic auth secret for CMS protection.

#### Basic Auth for CMS Protection

The CMS subdomain is protected with basic auth to prevent search engines from indexing duplicate content.

 when creating the secret
- The format is `username:hashedpassword`
- You can add multiple users by separating them with newlines

**Example with multiple users:**

```bash
echo -e "admin:\$2y\$10\$hash1\nuser2:\$2y\$10\$hash2" | docker secret create basic_auth_password -
```

### 2. Verify Secrets

Check that the secret was created successfully:

```bash
docker secret ls
```

You should see:
- `basic_auth_password`

### 3. Update Configuration

1. **Update domains** in `docker-stack.yml`:
   - Replace `yourdomain.com` with your actual domain
   - Replace `cms.yourdomain.com` with your CMS subdomain

2. **Update Statamic Docker image** in `docker-stack.yml`:
   - Replace `your-statamic-image:latest` with your actual Statamic Docker image

### 4. Deploy the Stack

```bash
# Deploy the stack
docker stack deploy -c docker-stack.yml statamic-blog

# Check deployment status
docker stack services statamic-blog
docker stack ps statamic-blog
```

### 5. Configure Statamic

Once deployed, configure your Statamic application to:

1. **Use SQLite database** - ensure your application is configured for SQLite (the environment variables in `docker-stack.yml` should handle this)
2. **Configure asset storage** - assets will be stored in `/app/public/assets` (mounted as a Docker volume)
3. **Configure Glide cache** - processed images will be cached in `/app/public/glide` (mounted as a Docker volume)
4. **Set up static generation triggers** - implement your custom logic to generate static files to `/app/static` when content is updated

## File Structure

```
project/
├── docker-stack.yml          # Main stack configuration
├── nginx-static.conf         # Nginx config for static site
└── README.md                 # This file
```

## Accessing Services

After deployment:

- **Main website**: https://yourdomain.com
- **CMS**: https://cms.yourdomain.com (basic auth required)

## Updating the Stack

```bash
# Pull latest images and update
docker stack deploy -c docker-stack.yml statamic-blog
```

## Troubleshooting

### Check service logs:
```bash
# View logs for specific service
docker service logs statamic-blog_statamic-dynamic
docker service logs statamic-blog_statamic-static
```

### Verify secrets are mounted:
```bash
# Exec into a service to check secrets
docker exec -it $(docker ps -q -f name=statamic-blog_statamic-dynamic) ls -la /run/secrets/
```

## Security Notes

- All sensitive credentials are stored as Docker secrets
- CMS is protected with basic auth to prevent SEO penalties
- Assets are served directly from Docker volumes for optimal performance

## Backup Strategy

Regular backups should include:
- **SQLite database volume**: `statamic-database`
- **Assets volume**: `statamic-assets`
- **Glide cache volume**: `statamic-glide`
- **Generated static files**: `static-output`

```bash
# Example backup commands
docker run --rm -v statamic-blog_statamic-database:/data -v $(pwd):/backup alpine tar czf /backup/database-backup.tar.gz -C /data .
docker run --rm -v statamic-blog_statamic-assets:/data -v $(pwd):/backup alpine tar czf /backup/assets-backup.tar.gz -C /data .
docker run --rm -v statamic-blog_static-output:/data -v $(pwd):/backup alpine tar czf /backup/static-backup.tar.gz -C /data .
```
