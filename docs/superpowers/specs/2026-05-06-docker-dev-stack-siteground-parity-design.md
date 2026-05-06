# Docker Dev Stack with SiteGround Parity — Design

**Date:** 2026-05-06
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 12 + Filament 4)

## Goal

Provide a `docker compose up` local development environment whose runtime mirrors the production target — **SiteGround shared hosting** — so that bugs in routing, `.htaccess` rules, mod_rewrite behavior, and driver assumptions are surfaced locally instead of after upload.

The stack must run cleanly on a Linux host. It is not intended for production deployment; SiteGround is not Docker-hosted.

## Constraints driving the design

SiteGround shared hosting characteristics that the dev stack must mirror:

- **Web server:** Apache with `.htaccess` (the project's existing `public/.htaccess` and any `public_html/.htaccess` rewrites must be exercised locally).
- **PHP:** mod_php-style request lifecycle.
- **Database:** MySQL.
- **Not available on shared hosting:** Redis, persistent queue workers, FrankenPHP/Octane, Node at runtime.
- **Async work model:** cron-driven (`schedule:run` once per minute), with `QUEUE_CONNECTION=sync` in production.
- **Driver defaults documented for prod:** `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`.

The dev stack therefore avoids services and extensions that don't exist on the production host. Adding them locally would create divergence and is explicitly out of scope.

## Services

Two services only:

### `app`

- **Base image:** `php:8.3-apache`.
- **PHP extensions:** `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd` (configured with freetype + jpeg), `intl`, `zip`.
- **Composer:** copied from the official `composer:2` image into `/usr/bin/composer`.
- **Apache modules:** `mod_rewrite` enabled.
- **Document root:** `/var/www/html/public` (configured via custom vhost).
- **Composer install at build time:** `composer install --prefer-dist --no-interaction --optimize-autoloader` (with dev dependencies included, since this is a dev image).
- **UID/GID parity:** build args `WWWUSER` and `WWWGROUP` (default `1000`) used to align the `www-data` user with the host user, so files created in the container are not root-owned on the host.

### `mysql`

- **Image:** `mysql:8.0`.
- **Healthcheck:** `mysqladmin ping -p$MYSQL_ROOT_PASSWORD` with sane retries/timeout.
- **Persistence:** named volume `cars-mysql-data`.
- **Forwarded port:** `${FORWARD_DB_PORT:-3307}:3306` (matches the existing `.env` value to avoid clashing with a host-side MySQL).

No nginx. No Redis. No queue worker. No scheduler. No Node container.

## Files to create / modify / delete

| File | Action | Purpose |
|---|---|---|
| `Dockerfile` | **Rewrite** | Base `php:8.3-apache`; install extensions; enable mod_rewrite; set DocumentRoot via vhost; copy in `docker/apache/000-default.conf`; accept `WWWUSER`/`WWWGROUP` build args; remove phpredis (no Redis on SiteGround) |
| `docker/apache/000-default.conf` | **New** | Apache vhost on :80 with `DocumentRoot /var/www/html/public` and `AllowOverride All` so `public/.htaccess` is honored |
| `docker/nginx/default.conf` | **Delete** | Replaced by Apache vhost |
| `docker/nginx/` | **Delete** | Empty after the file removal |
| `docker-compose.yml` | **Rewrite** | Replace Sail-based config with custom `app` (built from local Dockerfile) + `mysql` 8.0; bind-mount project root; anonymous volumes on `vendor/` and `node_modules/`; `app` `depends_on` `mysql: service_healthy`; named network `cars-net`; named volume `cars-mysql-data` |
| `.dockerignore` | **Keep** | Existing content is already correct (`vendor`, `node_modules`, `.env`, `.git`, etc.) |
| `.env` | **Modify** | `CACHE_STORE=database` → `file`; `SESSION_DRIVER=database` → `file`; keep `QUEUE_CONNECTION=sync`; confirm `DB_HOST=mysql`; set `APP_PORT=8080` |
| `.env.example` | **Modify** | Mirror the `.env` driver alignment so new clones get prod-parity defaults |
| `DEPLOYMENT.md` §2.5 | **Modify** | Fix `CACHE_DRIVER=file` → `CACHE_STORE=file` (Laravel 12 key); add `SESSION_DRIVER=file` to the recommended `.env` block |
| `README.md` | **Modify** | Add a short "Run with Docker" subsection containing the workflow below |

## Apache vhost (the parity lever)

`docker/apache/000-default.conf`:

- Listens on `*:80`.
- `DocumentRoot /var/www/html/public`.
- `<Directory /var/www/html/public>` block with `AllowOverride All` and `Require all granted`.
- `ErrorLog` and `CustomLog` to stderr/stdout so logs flow through `docker compose logs`.

`AllowOverride All` is non-negotiable. Without it, `public/.htaccess` is ignored and any rewrite-related divergence between dev and SiteGround re-emerges.

## docker-compose.yml shape

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
      args:
        WWWUSER: '${WWWUSER:-1000}'
        WWWGROUP: '${WWWGROUP:-1000}'
    image: cars-images-api/app
    ports:
      - '${APP_PORT:-8080}:80'
    environment:
      - APP_ENV=${APP_ENV:-local}
    volumes:
      - .:/var/www/html
      - /var/www/html/vendor
      - /var/www/html/node_modules
    depends_on:
      mysql:
        condition: service_healthy
    networks: [cars-net]
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    ports:
      - '${FORWARD_DB_PORT:-3307}:3306'
    environment:
      MYSQL_ROOT_PASSWORD: '${DB_PASSWORD}'
      MYSQL_DATABASE: '${DB_DATABASE}'
      MYSQL_USER: '${DB_USERNAME}'
      MYSQL_PASSWORD: '${DB_PASSWORD}'
    volumes:
      - cars-mysql-data:/var/lib/mysql
    networks: [cars-net]
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-p${DB_PASSWORD}"]
      retries: 5
      timeout: 5s
      interval: 10s
    restart: unless-stopped

networks:
  cars-net:
    driver: bridge

volumes:
  cars-mysql-data:
    driver: local
```

The `version:` key is intentionally omitted (deprecated in modern Docker Compose).

## Local workflow

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
# open http://localhost:8080
```

`composer install` is documented as an explicit step even though the image performs it at build time, because the bind-mount + anonymous-vendor pattern means the user may want to refresh deps when `composer.json` changes without a full rebuild.

## Best-practice rationale

- **Single Apache container, not nginx + fpm.** SiteGround uses Apache; routing parity is the entire point. nginx adds a second container, a second config file, and a divergent rewrite syntax.
- **`AllowOverride All`** so `public/.htaccess` is read. This is what catches `.htaccess`-level bugs (the Livewire rewrite call-out in `DEPLOYMENT.md` is exactly the kind of thing this prevents from regressing).
- **`APP_PORT=8080` default** avoids the Linux privileged-port (≤1024) issue. Users can override via `.env` if they want `:80`.
- **UID/GID build args** prevent the host filesystem from accumulating root-owned files written by Apache.
- **Anonymous volumes on `vendor/` and `node_modules/`** so the host's empty (or stale) `vendor/` doesn't mask the container's installed deps.
- **MySQL healthcheck + `service_healthy` dependency** so the first `up` doesn't race the DB.
- **Named project resources** (`cars-net`, `cars-mysql-data`, `cars-images-api/app`) instead of inherited Sail names — clearer in `docker ps` and avoids collision with other Sail projects on the same host.
- **Drop phpredis from the Dockerfile.** It's a footgun: someone flips `CACHE_STORE=redis` in dev, it works locally, then breaks on SiteGround.
- **Align `.env` drivers to file/sync.** The `database` cache and session drivers happen to work but diverge from documented prod settings; aligning now means prod-replica behavior locally and removes a class of "works on my machine" bug.

## Explicitly out of scope

- **Redis service / phpredis extension** — not available on SiteGround shared.
- **Queue worker container** — SiteGround uses cron + `QUEUE_CONNECTION=sync`.
- **Scheduler container** — same; cron handles `schedule:run` on prod, run manually in dev when needed.
- **Node/Vite container** — Filament ships compiled assets; no asset customization in this project.
- **Production Docker image** — SiteGround isn't Docker-hosted. Revisit only if the host changes.
- **Migration of `CACHE_STORE`/`SESSION_DRIVER` data** between drivers — there is no production data on these drivers yet; switching defaults is safe.

## Acceptance criteria

1. `docker compose build` succeeds from a clean clone.
2. `docker compose up -d` starts both services; `mysql` reaches healthy state; `app` does not log connection errors.
3. After running the documented workflow, `http://localhost:8080` serves the Laravel welcome / app, and `http://localhost:8080/admin` reaches the Filament login.
4. `public/.htaccess` rewrites are active (verified by hitting a route that requires rewrite, e.g. `/admin`).
5. Files created in the container by Apache appear on the host owned by the host user (verified by, e.g., `ls -l storage/logs/laravel.log` after a request).
6. `docker compose down` followed by `docker compose up -d` preserves the MySQL data.
7. `DEPLOYMENT.md` §2.5 shows `CACHE_STORE=file` and `SESSION_DRIVER=file`.
8. No `docker/nginx/` directory remains in the repo.
9. The `Dockerfile` no longer references `pecl install redis` or `docker-php-ext-enable redis`.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Existing dev users have `database`-driver cache/session data they care about | Document in the implementation plan that this is a dev-environment-only change; production prod settings already use `file` per `DEPLOYMENT.md` |
| Port 8080 already bound on host | `APP_PORT` is overridable via `.env` |
| Host MySQL on 3306 conflicts with compose | `FORWARD_DB_PORT` is already `3307` in `.env`, preserved in the new compose |
| `.htaccess` AllowOverride disabled by accident in future Apache config edits | Acceptance criterion 4 verifies it; vhost has a comment explaining why it must stay `All` |
