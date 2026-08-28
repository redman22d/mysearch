# Tutor Course Search Pro — Base44 Dev Environment

## What this is

A WordPress plugin (`tutor-course-search-pro`) that adds marketplace-style
search, filtering, sorting, sponsorship tiers, and autocomplete to the
**Tutor LMS** course archive.  It is NOT a standalone app — it requires a
running WordPress + Tutor LMS installation.

## Stack

| Service    | Image / base              | Role                          |
|------------|---------------------------|-------------------------------|
| `db`       | `mariadb:10.11`           | MySQL database                |
| `wordpress`| `wordpress:php8.2-apache` | Apache + PHP + WordPress core |
| `setup`    | same image + WP-CLI       | One-shot: install WP, Tutor LMS, plugin, sample data |

## How to run

```bash
docker compose -f docker-compose.base44.yml up -d --build
```

The `setup` service runs once and exits. It installs WordPress, downloads
and activates Tutor LMS from WordPress.org, activates this plugin, flushes
rewrite rules, and creates 12 sample courses with categories, tags, prices,
levels, ratings, enrollments, and sponsorship tiers.

## Key files (Base44-specific, not part of the plugin)

- `docker-compose.base44.yml` — compose stack
- `Dockerfile.base44` — WordPress image + WP-CLI + mu-plugin
- `setup.sh` — one-shot setup script (run inside the `setup` container)
- `setup-data.php` — sample data creation (run via `wp eval-file`)
- `mu-plugins/base44-helpers.php` — forces HTTPS behind the preview proxy
  and redirects `/` → `/courses/` so the preview lands on the filter bar

## Dev notes

- The plugin source is bind-mounted into `wp-content/plugins/`. PHP has no
  build step, so edits are reflected on the next request (no reload needed).
- CSS/JS edits are also live (browser cache aside).
- WordPress admin: `admin` / `admin` at `/wp-login.php`
- The course archive (where the filter bar appears) is at `/courses/`
- `WP_HOME` / `WP_SITEURL` are set via `WORDPRESS_CONFIG_EXTRA` to the preview
  URL derived from `BASE44_PUBLIC_HOST_SUFFIX`. The mu-plugin forces
  `$_SERVER['HTTPS'] = 'on'` so WordPress generates https:// asset URLs.
- No external secrets are required.

## Verifying it works

```bash
# Services up
docker compose -f docker-compose.base44.yml ps

# Courses page serves the filter bar
curl -sf -H "Host: $PREVIEW_HOST" http://localhost:3000/courses/ | grep tcsp-filter-bar

# Plugin assets load
curl -sf -o /dev/null -w '%{http_code}' -H "Host: $PREVIEW_HOST" \
  http://localhost:3000/wp-content/plugins/tutor-course-search-pro/assets/css/tcsp-filters.css
```
