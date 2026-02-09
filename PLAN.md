# Everyday Accounts Blog — Project Plan

This document outlines the roadmap for transforming the current Statamic 5 site into a flat-file, git-tracked, Cloudflare-deployed blog that matches the existing everydayaccountsblog.com WordPress design.

---

## Current State

- **Statamic 5 on Laravel 12**, content migrated from WordPress
- **All content lives in flat files** in `content/` — Eloquent driver has been removed
- **413 blog posts imported** from WordPress REST API, plus categories and tags taxonomies
- **Theme is a minimal starter** — teal accent, Tailwind + Alpine, no resemblance to the WordPress blog
- **Docker Swarm production stack** with FrankenPHP, Traefik, and nginx for static serving
- **SSG already configured** — outputs to `storage/app/static`
- **Two import commands exist**: one from local JSON, one from the WordPress REST API
- **Blueprints defined**: articles (title, content, excerpt, author), pages (title, content, template), categories and tags taxonomies, global settings (site_name, social grid)

---

## Target State

| Concern | Target |
|---|---|
| Content storage | Flat files in `content/`, tracked in git |
| Media storage | Local filesystem on VM, backed up to Cloudflare R2 |
| CMS hosting | Local VM (private, admin-only) |
| Public site | Static HTML on Cloudflare Pages, built via `ssg:generate` |
| Theme | Faithful recreation of the WordPress everydayaccountsblog.com design |
| Repo | Full project (minus media binaries) lives on GitHub |

---

## Work Streams

### 1. Migrate from Eloquent Driver to Flat Files

**Goal:** Remove the Eloquent driver so all content (collections, entries, taxonomies, globals, blueprints, navigation trees) is stored as YAML/Markdown files in `content/` and tracked in git.

**Steps:**

1. **Export existing data from SQLite to flat files.** Statamic's Eloquent driver package includes an `statamic:eloquent:export-to-file` command (or the reverse of the import commands). Verify this works for all content types: collections, entries, taxonomies, terms, globals, navigation trees, asset metadata.
2. **Remove the `statamic/eloquent-driver` package** from `composer.json`.
3. **Revert config changes:**
   - `config/statamic/eloquent-driver.php` — delete entirely.
   - `config/statamic/users.php` — switch repository back to `file`.
   - `config/database.php` — SQLite can remain available for other Laravel features (cache, sessions, queue) but is no longer needed for content.
   - `config/auth.php` — revert guards/providers to Statamic's file-based user driver.
4. **Update `App\Models\User`** — the `HasUuids` trait was added for Eloquent users. Statamic's file-based users use their own ID scheme. Evaluate whether this model is still needed or can be replaced by Statamic's default user handling.
5. **Clean up migrations** — the Eloquent driver migrations (`statamic_auth_tables`, `create_revisions_table`, Eloquent content tables) are no longer needed. They can be removed or left inert.
6. **Verify all content** renders correctly from flat files. Spot-check articles, pages, taxonomy terms, global settings, and navigation.
7. **Commit the flat-file content directory** to git.

**Risks & Considerations:**
- The export command may not handle every content type perfectly. Manual cleanup of YAML files may be needed.
- Asset metadata (`content/assets/`) should export cleanly, but verify image references in entries still resolve.
- User accounts will need to be recreated as YAML files in `users/`.

---

### 2. Redesign the Antlers Theme

**Goal:** Recreate the look and feel of everydayaccountsblog.com in Antlers + TailwindCSS.

**Reference Design (current WordPress site):**

- **Header:** Large custom header image (`blogheader9-1.png`) with site title, followed by the tagline: *"We get one story, you and I, and one story alone." — Donald Miller*
- **Navigation:** Hierarchical dropdown menus — *Home, About Me, Travel with Me* (with geographic sub-menus: Central America, Europe, South America, United States), *Travel Tips* (Travel Favorites, Travel Prep), *Other Series* (Currently, Lazy Days with Banana, Scenes From the Week/Month)
- **Color palette:** Warm and personal — peachy-orange accents (`#ffbc97`), soft neutrals, textured background image
- **Blog listing:** Chronological posts with featured images, titles, dates, author, excerpts, and category/tag labels
- **Sidebar (right):** Current Location widget, Etsy shop promo, Goodreads reading challenge, monthly/yearly archives, category list
- **Footer:** Tagline repeated, social media icons (Pinterest, Twitter/X, Instagram, Facebook)
- **Overall aesthetic:** Warm, intimate, scrapbook-like — a personal travel and lifestyle journal

**Steps:**

1. **Capture detailed screenshots** of the WordPress site for reference (home, single post, about page, category archive, mobile views).
2. **Design approach: modernized successor.** Keep the warmth, color palette, and personality but modernize the layout — cleaner grid, better mobile experience, modern typography. Use the WordPress design as a mood board rather than a pixel-perfect spec.
3. **Design system / Tailwind config updates:**
   - Replace teal palette with warm palette: peachy-orange (`#ffbc97`), soft cream/tan backgrounds, warm grays.
   - Choose fonts that echo the WordPress site (or select modern equivalents). The WP site uses a mix of serif and sans-serif.
   - Consider keeping `important: true` in Tailwind config or removing it if specificity issues are resolved.
4. **Template work:**

   | Template | Notes |
   |---|---|
   | `layout.antlers.html` | Add textured/warm background, restructure for sidebar layout on wider screens |
   | `_nav.antlers.html` | Rebuild as hierarchical dropdown with geographic sub-menus. Mobile hamburger menu. |
   | `_header.antlers.html` (new) | Header image/banner area with site title and tagline |
   | `_sidebar.antlers.html` (new) | Replicate WP sidebar: Recent Posts, Goodreads widget, archives, categories, Current Location, Etsy shop, social links |
   | `_footer.antlers.html` | Tagline, social icons (Pinterest, Instagram, Facebook, etc.) |
   | `home.antlers.html` | Featured posts with images, warm styling |
   | `articles/index.antlers.html` | Post list with featured images, dates, excerpts, category tags |
   | `articles/show.antlers.html` | Single post with author, date, categories/tags, related posts |
   | `topics/show.antlers.html` | Category/tag archive page |
   | `page.antlers.html` | About Me and other static pages |

5. **Navigation structure: hybrid approach.** Top-level nav items (Home, About Me, Travel with Me, Travel Tips, Other Series) are manually defined in a Statamic navigation tree. Sub-menus under each top-level item are auto-populated from child taxonomy terms (e.g., Travel with Me > Europe > France comes from a hierarchical topics taxonomy).
6. **Blueprint updates:**
   - Add a `featured_image` (asset) field to the articles blueprint.
   - Consider adding a `series` taxonomy or field for grouping posts (Currently, Scenes From the Week, etc.).
   - Add archive/category widgets to global settings or build them dynamically from collection data.
7. **Typography and content styling:** Update `resources/css/style.css` to match the warm, readable style of the WordPress site — serif body text for post content, appropriate heading sizes, blockquote styling, image treatments.
8. **Responsive design:** Ensure all templates work well on mobile. The WordPress site's responsiveness is mediocre — this is a chance to improve.

---

### 3. Media Strategy — Local + Cloudflare R2 Backup

**Goal:** Media files live on the VM's local filesystem for CMS use, with automated backup to Cloudflare R2.

**Steps:**

1. **Configure a Statamic asset container** that uses the `local` filesystem driver, storing files in `storage/app/assets/` (or `public/assets/`). This is Statamic's default behavior.
2. **Set up Cloudflare R2 bucket** for backups.
3. **Install `league/flysystem-aws-s3-v3`** (R2 is S3-compatible) and configure a second filesystem disk in `config/filesystems.php` pointing to the R2 bucket.
4. **Automated backup script:** A scheduled Laravel command or cron job that syncs the local asset directory to R2. Options:
   - Laravel scheduled command using Flysystem to mirror files.
   - `rclone` configured with R2 credentials, triggered by cron (simpler, battle-tested).
   - Statamic event listener that uploads to R2 whenever an asset is uploaded/updated in the CP.
   - Recommendation: `rclone sync` via cron is the simplest and most reliable. Run it nightly or on whatever cadence makes sense.
5. **Add `storage/app/assets/` (or wherever media lives) to `.gitignore`** — media stays out of git.
6. **For SSG builds:** Media needs to be accessible during `ssg:generate`. Since the CMS and the build both run on the same VM, local media will be available. The generated static site will reference images at their public URLs — these will need to be served from either R2 directly or a CDN in front of R2 (see Stream 5).

**Image serving:** Images will be served from Cloudflare R2 via a custom domain (e.g., `media.everydayaccountsblog.com`). Static HTML references absolute URLs to the media domain. This keeps Cloudflare Pages deploys lightweight (HTML/CSS/JS only), and R2 has free egress on Cloudflare's network.

**Legacy `/wp-content/` image paths:** The current production stack has a dedicated nginx service (`wordpress-images` in `docker-stack.yml` + `nginx-wp-content.conf`) that intercepts `/wp-content/` requests on the main domain and serves images from `public/assets/wp-content/`. This entire mechanism gets replaced when we move to Cloudflare Pages + R2:

- Upload all `/wp-content/uploads/` images to R2, preserving the directory structure.
- Rewrite image URLs in blog post content to point to the R2 media domain (e.g., `media.everydayaccountsblog.com/wp-content/uploads/2021/09/image.png`).
- Add a `_redirects` rule on Cloudflare Pages to redirect any remaining `/wp-content/*` requests to the R2 media domain as a safety net for external links.
- Retire `nginx-wp-content.conf`, the `wordpress-images` Docker service, and the `public/assets/wp-content/` local directory.

---

### 4. VM-Based CMS Hosting (Local Admin)

**Goal:** Run the Statamic CMS on a local VM for content management, not exposed to the public internet.

**Steps:**

1. **Simplify the Docker setup.** The current `docker-stack.yml` is built for a full production deployment with Traefik, TLS, and multiple services. For a local VM:
   - A simple `docker-compose.yml` running the Statamic app (FrankenPHP) is sufficient.
   - No need for Traefik, TLS, or the static nginx service on the VM itself.
   - The `docker-compose.dev.yml` is close to what's needed — adapt it for the VM environment.
2. **Or skip Docker entirely** for the VM and run Statamic natively with `php artisan serve` or a local nginx/Apache + PHP-FPM setup. This is simpler for a single-user admin environment.
3. **Data persistence:** With flat files, all content is in the repo. `git pull` on the VM gets latest code, `git push` after edits commits content changes. The VM just needs:
   - PHP 8.4 + required extensions
   - Composer
   - Node.js (for Vite asset building, if needed on the VM — or build assets in CI)
   - The git repo cloned
   - Local media storage directory
4. **Access:** Since this is a local VM, access is inherently restricted. No need for complex auth layers beyond Statamic's built-in CP authentication.
5. **Consider Tailscale or similar** if you want to access the CMS from outside your local network without exposing ports to the public internet.

---

### 5. Static Site Deployment to Cloudflare Pages

**Goal:** Run `ssg:generate` and deploy the output to Cloudflare Pages for the public-facing site.

**Steps:**

1. **SSG configuration review:**
   - Current config outputs to `storage/app/static` — this is fine.
   - Ensure all needed URLs are crawled/generated (articles, pages, taxonomy pages, pagination, feeds).
   - The SSG config (`config/statamic/ssg.php`) may need a `urls` list for any routes that aren't discoverable from the sitemap.
2. **Image URL rewriting:** If using Option A from Stream 3 (R2-hosted media), configure the asset container's URL to point to the R2/CDN domain so that generated HTML references the correct image URLs.
3. **Build pipeline — GitHub Actions CI:**
   - Statamic's Git Integration auto-commits and pushes CP changes to GitHub.
   - GitHub Actions workflow triggers on push to `main`:
     1. Check out repo.
     2. Install PHP, Composer, Node dependencies.
     3. Build frontend assets (`npm run build`).
     4. Run `php please ssg:generate`.
     5. Deploy `storage/app/static` to Cloudflare Pages via Wrangler action.
   - **Media in CI:** Since images are served from R2 via absolute URLs (not processed by Glide at build time), the GitHub Actions runner does not need local copies of media files. The asset container URL points to the R2 domain, and the SSG generates HTML with those URLs baked in.

4. **Cloudflare Pages project setup:**
   - Create a Cloudflare Pages project (direct upload mode, not git-connected — since we're deploying built output, not building on Cloudflare).
   - Configure custom domain: `everydayaccountsblog.com` and `www.everydayaccountsblog.com`.
   - Set up redirects (`_redirects` file in static output) for www-to-apex or vice versa.
5. **URL structure:** Configure the articles collection to use the same URL pattern as WordPress (e.g., `/{year}/{month}/{slug}`) so no post redirects are needed. This is set in the collection's YAML config via the `route` property.
6. **RSS feed:** Ensure the SSG generates an RSS/Atom feed if the WordPress site had one (subscribers may depend on it).

---

### 6. Git Strategy & `.gitignore`

**Goal:** The full project — code, content, config, templates — lives on GitHub. Only media binaries are excluded.

**Tracked in git:**
- `content/` — all YAML/Markdown files (collections, entries, taxonomies, globals, navigation trees, blueprints)
- `resources/` — views, CSS, JS, blueprints, fieldsets
- `config/` — all configuration
- `app/` — Laravel application code
- `routes/`, `database/`, `tests/`, `lang/`
- `public/` — built assets (or `.gitignore` built assets and build in CI)
- Docker files, composer/package configs

**Excluded from git (`.gitignore`):**
- `storage/app/assets/` (or wherever media lives) — images, uploaded files
- `storage/app/static/` — generated SSG output
- `database/*.sqlite` — no longer the content store; only used for Laravel internals
- `node_modules/`, `vendor/` — standard
- `.env` — secrets

**Content workflow:**
1. Edit content in Statamic CP on the VM.
2. Statamic writes flat files to `content/`.
3. Statamic's Git Integration auto-commits and pushes to GitHub on CP save.
4. GitHub Actions CI builds the static site and deploys to Cloudflare Pages.

---

### 7. WordPress Migration Completeness Check

**Goal:** Ensure all WordPress content and functionality is accounted for before decommissioning the WordPress site.

**Checklist:**

- [x] **All blog posts imported** — content migration from WordPress is complete.
- [x] **Categories and tags migrated** — WordPress categories mapped to Statamic topics taxonomy.
- [x] **Pages migrated** — About Me and other static pages.
- [ ] **Featured images** — verify these are linked correctly in Statamic entries.
- [ ] **Image references in post content** — rewrite inline `/wp-content/` URLs in post content to point to R2 media domain.
- [x] **Comments** — Site already uses Disqus. Embed the Disqus widget in the new article template. URL structure match ensures existing threads carry over automatically.
- [ ] **RSS feed subscribers** — maintain feed URL compatibility or set up redirects.
- [x] **SEO / URL redirects** — using the same WordPress URL structure (`/{year}/{month}/{slug}/`), so no redirects needed for posts.
- [ ] **Widgets / sidebar content** — Replicate the WordPress sidebar: Recent Posts, Goodreads, archives, categories, Current Location, Etsy shop, social links.
- [ ] **wp-content legacy images** — upload images to R2 preserving `/wp-content/uploads/` paths. Rewrite URLs in post content to use R2 media domain. Add `_redirects` fallback on Cloudflare Pages. Retire `nginx-wp-content.conf`, the `wordpress-images` Docker service, and `public/assets/wp-content/`.

---

## Recommended Order of Execution

```
Phase 1: Foundation ✓ COMPLETE
├── 1.1  ✓ Migrate Eloquent → flat files (Stream 1)
├── 1.2  ✓ Verify all content exports correctly (413 articles, 488 taxonomy terms, 7 blueprints, 1 global set)
├── 1.3  Commit flat-file content to git
└── 1.4  Update .gitignore for new architecture

Phase 2: Theme
├── 2.1  Capture WordPress design reference (screenshots, assets)
├── 2.2  Update Tailwind config (colors, fonts, spacing)
├── 2.3  Build layout shell (header, nav, sidebar, footer)
├── 2.4  Build page templates (home, article list, single article, taxonomy, about)
├── 2.5  Content typography and styling
├── 2.6  Responsive / mobile polish
└── 2.7  Navigation tree setup

Phase 3: Infrastructure
├── 3.1  Set up Cloudflare R2 bucket
├── 3.2  Configure media backup (rclone or similar)
├── 3.3  Configure asset container URL for R2/CDN
├── 3.4  Set up Cloudflare Pages project
├── 3.5  Configure SSG for clean static output
├── 3.6  First deploy to Cloudflare Pages
└── 3.7  Configure articles collection route to match WP URL pattern

Phase 4: VM & Workflow
├── 4.1  Set up VM with PHP, Composer, Node
├── 4.2  Simplify Docker setup (or go native)
├── 4.3  Configure Statamic Git Integration for auto-commits + push
├── 4.4  Set up GitHub Actions workflow (build SSG → deploy to CF Pages)
├── 4.5  Document the content → deploy workflow
└── 4.6  Decommission old WordPress site + Docker Swarm stack
```

Phases 1 and 2 can overlap. Phase 3 depends on Phase 1 being complete. Phase 4 can begin alongside Phase 3.

---

## Decisions (Resolved)

1. **Theme fidelity** → **Modernized successor.** Keep the warmth, color palette, and personality but modernize the layout — cleaner grid, better mobile experience, modern typography. Use the WordPress design as a mood board, not a pixel-perfect spec.
2. **Image serving** → **R2 with custom domain** (e.g., `media.everydayaccountsblog.com`). Static HTML references absolute URLs to the media domain. Keeps Cloudflare Pages deploys lightweight.
3. **Build pipeline** → **GitHub Actions CI.** Statamic's Git Integration auto-commits and pushes CP changes to GitHub. GitHub Actions builds the static site and deploys to Cloudflare Pages on every push to `main`.
4. **Comments** → **Keep Disqus.** The WordPress site already uses Disqus, so comments live in Disqus's system. Embed the Disqus widget on article pages in the new theme. Since the URL structure will match WordPress, existing comment threads will automatically appear on the correct posts.
5. **Navigation** → **Manual top-level, dynamic children.** Top-level nav items (Travel with Me, Travel Tips, Other Series) are manually defined. Sub-menus are auto-populated from child taxonomy terms.
6. **Sidebar** → **Replicate the WordPress sidebar.** Include: Recent Posts, Goodreads reading challenge, monthly/yearly archives, category list, Current Location widget, Etsy shop link, social media links.
7. **Legacy URLs** → **Match the WordPress URL structure** (e.g., `/2023/05/post-slug/`) so no post redirects are needed. Images are already organized in a directory structure that preserves old `/wp-content/` paths — only the domain/subdomain needs updating when images move to R2.
