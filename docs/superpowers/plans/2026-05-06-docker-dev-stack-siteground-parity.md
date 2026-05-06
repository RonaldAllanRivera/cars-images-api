# Docker Dev Stack with SiteGround Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the in-progress, half-Sail/half-custom Docker config with a single `php:8.2-apache` + MySQL stack that mirrors SiteGround shared hosting, so routing, `.htaccess`, and driver-related bugs surface in dev.

**Architecture:** Two-service `docker compose` (`app` = `php:8.2-apache` with mod_rewrite + Composer + needed PHP extensions; `mysql` = `mysql:8.0` with healthcheck and named volume). Apache vhost serves `/var/www/html/public` with `AllowOverride All` so Laravel's `public/.htaccess` is honored exactly as it will be on SiteGround. No Redis, no queue worker, no scheduler, no Node container — all match production.

**Tech Stack:** Docker, Docker Compose v2, Apache 2.4 (mod_php-style via php:8.2-apache), Composer 2, MySQL 8.0, Laravel 12, Filament 4.

**Working directory for all commands:** `/home/allan/code/laravel/cars-images-api`.

**Note on TDD framing:** This work is infrastructure config — no unit-test framework applies. Each task's "test" is an operational verification command (file contents, `docker compose config`, `docker compose build`, HTTP response, file ownership, MySQL data persistence). The final task does end-to-end acceptance against the spec's acceptance criteria.

---

## Task 1: Add Apache vhost (parity lever)

This vhost replaces nginx as the local web server. `AllowOverride All` is the parity lever — without it, `public/.htaccess` is ignored locally and SiteGround divergence regresses silently.

**Files:**
- Create: `docker/apache/000-default.conf`

- [ ] **Step 1: Create the vhost file**

Create `docker/apache/000-default.conf` with:

```apache
<VirtualHost *:80>
    ServerName cars-images-api.test
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        Options Indexes FollowSymLinks
        # MUST stay "All" so public/.htaccess is honored.
        # This is the parity lever with SiteGround Apache + .htaccess.
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /dev/stderr
    CustomLog /dev/stdout combined
</VirtualHost>
```

- [ ] **Step 2: Verify file content**

Run: `cat docker/apache/000-default.conf | grep -E "AllowOverride All|DocumentRoot /var/www/html/public"`
Expected: both lines present.

- [ ] **Step 3: Commit**

```bash
git add docker/apache/000-default.conf
git commit -m "feat(docker): add Apache vhost for local dev with AllowOverride All"
```

---

## Task 2: Rewrite Dockerfile to php:8.2-apache

Switch base image, drop phpredis (footgun on SiteGround), enable mod_rewrite, install vhost, accept UID/GID build args so bind-mounted files don't end up root-owned on the host.

**Files:**
- Modify: `Dockerfile` (full rewrite)

- [ ] **Step 1: Replace Dockerfile contents**

Overwrite `Dockerfile` with:

```dockerfile
FROM php:8.2-apache

ARG WWWUSER=1000
ARG WWWGROUP=1000

# System deps + PHP extensions required by Laravel + Filament
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libicu-dev \
        libssl-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite so Laravel's public/.htaccess works.
RUN a2enmod rewrite

# Apache vhost: DocumentRoot -> /var/www/html/public, AllowOverride All
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Composer (from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Align www-data UID/GID with host user so bind-mounted files stay host-owned.
RUN usermod -u ${WWWUSER} www-data \
    && groupmod -g ${WWWGROUP} www-data

WORKDIR /var/www/html

# Copy app source for the build-time composer install. The bind-mount in
# docker-compose.yml will overlay /var/www/html at runtime; the anonymous
# volume on /var/www/html/vendor preserves the install below.
COPY . /var/www/html

RUN composer install \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
```

- [ ] **Step 2: Verify Dockerfile shape**

Run: `grep -E "^FROM php:8.2-apache$|a2enmod rewrite|pecl install redis" Dockerfile`
Expected: `FROM php:8.2-apache` and `a2enmod rewrite` lines present; `pecl install redis` **absent** (no output for that pattern).

- [ ] **Step 3: Confirm build context will succeed (lint)**

Run: `docker compose config --quiet`
Expected: exits 0 with no output. (This validates compose still parses; we haven't yet rewritten compose, so this just confirms the unchanged Sail-based compose still parses. It's a sanity gate before the next task.)

If `docker compose` is not installed or fails, skip this lint step and continue — the real build verification is in Task 4.

- [ ] **Step 4: Commit**

```bash
git add Dockerfile
git commit -m "feat(docker): rewrite Dockerfile to php:8.2-apache, drop phpredis"
```

---

## Task 3: Remove obsolete nginx config

The nginx vhost is replaced by the Apache vhost from Task 1.

**Files:**
- Delete: `docker/nginx/default.conf`
- Delete: `docker/nginx/` (now empty)

- [ ] **Step 1: Remove the nginx files**

```bash
rm docker/nginx/default.conf
rmdir docker/nginx
```

- [ ] **Step 2: Verify removal**

Run: `ls docker/`
Expected: only `apache` directory listed; no `nginx`.

- [ ] **Step 3: Commit**

```bash
git add -A docker/
git commit -m "chore(docker): remove unused nginx vhost"
```

---

## Task 4: Rewrite docker-compose.yml

Replace the Sail-based compose with one that builds from the local Dockerfile and adds a healthchecked MySQL.

**Files:**
- Modify: `docker-compose.yml` (full rewrite)

- [ ] **Step 1: Replace docker-compose.yml contents**

Overwrite `docker-compose.yml` with:

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
      APP_ENV: '${APP_ENV:-local}'
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

The `version:` key is intentionally omitted (deprecated in modern Compose).

- [ ] **Step 2: Validate compose syntax**

Run: `docker compose config --quiet`
Expected: exits 0 with no output.

If it errors, re-read the YAML carefully (indent levels, quoting). Common pitfalls: tabs vs spaces, missing colons, env-var interpolation needing `'${...}'` quotes.

- [ ] **Step 3: Build the image**

Run: `docker compose build app`
Expected: build succeeds, ending with something like `=> => naming to docker.io/cars-images-api/app`.

If this fails, the failure is most likely from the `composer install` step in the Dockerfile. To diagnose: `docker compose build --progress=plain app` and inspect the output.

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml
git commit -m "feat(docker): rewrite compose to use local Dockerfile + healthchecked MySQL"
```

---

## Task 5: Align `.env` and `.env.example` to prod-parity drivers

Match the documented production driver settings (`file` cache/session, `sync` queue) and switch the default port to 8080 to avoid Linux privileged-port issues.

**Files:**
- Modify: `.env`
- Modify: `.env.example`

- [ ] **Step 1: Update `.env`**

Make these exact edits to `.env`:

- Change `APP_PORT=80` to `APP_PORT=8080`.
- Change `SESSION_DRIVER=database` to `SESSION_DRIVER=file`.
- Change `CACHE_STORE=database` to `CACHE_STORE=file`.
- Leave `QUEUE_CONNECTION=sync` as-is (already correct).
- Leave `DB_HOST=mysql` as-is (already correct for compose).

- [ ] **Step 2: Verify `.env`**

Run: `grep -E "^(APP_PORT|SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION|DB_HOST)=" .env`
Expected output:
```
APP_PORT=8080
SESSION_DRIVER=file
CACHE_STORE=file
DB_HOST=mysql
QUEUE_CONNECTION=sync
```
(Order may differ — that's fine.)

- [ ] **Step 3: Update `.env.example`**

`.env.example` currently uses empty placeholder values (e.g. `SESSION_DRIVER=""`). Set sensible defaults for the keys that drive parity, so a fresh clone running `cp .env.example .env` boots in Docker. Make these exact edits:

- Replace `SESSION_DRIVER=""` line with: `SESSION_DRIVER="file"`
- Replace `CACHE_STORE=""` line with: `CACHE_STORE="file"`
- Replace `QUEUE_CONNECTION=""` line with: `QUEUE_CONNECTION="sync"`
- Replace `DB_HOST=""` line with: `DB_HOST="mysql"`
- Replace `DB_PORT=""` line with: `DB_PORT="3306"`

Strip the trailing `# Provide a value for ...` comment from each of those five lines (they no longer need a value provided).

Leave the other empty placeholders alone — `APP_KEY`, `APP_NAME`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, etc. are still user-fill.

- [ ] **Step 4: Verify `.env.example`**

Run: `grep -E '^(SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION|DB_HOST|DB_PORT)=' .env.example`
Expected output:
```
SESSION_DRIVER="file"
CACHE_STORE="file"
QUEUE_CONNECTION="sync"
DB_HOST="mysql"
DB_PORT="3306"
```

- [ ] **Step 5: Commit**

`.env` is gitignored — only `.env.example` will be staged.

```bash
git add .env.example
git commit -m "chore(env): align example env to file/sync drivers and Docker DB host"
```

---

## Task 6: Fix `DEPLOYMENT.md` cache key typo

Laravel 12 reads `CACHE_STORE`, not the legacy `CACHE_DRIVER` key. The deployment doc currently shows the wrong one.

**Files:**
- Modify: `DEPLOYMENT.md` (line 210, inside §2.5)

- [ ] **Step 1: Fix the line**

In `DEPLOYMENT.md`, find the block:

```
# Caching / queueing (simple defaults)
QUEUE_CONNECTION=sync
CACHE_DRIVER=file
SESSION_DRIVER=file
```

Change `CACHE_DRIVER=file` to `CACHE_STORE=file` (single-line edit). Leave the surrounding lines alone.

- [ ] **Step 2: Verify the fix**

Run: `grep -n "CACHE_DRIVER\|CACHE_STORE" DEPLOYMENT.md`
Expected: a line containing `CACHE_STORE=file`. **No** lines containing `CACHE_DRIVER` (other than possibly in narrative prose; if present in a code block, fix it too).

- [ ] **Step 3: Commit**

```bash
git add DEPLOYMENT.md
git commit -m "fix(docs): use CACHE_STORE (Laravel 12 key) in deployment guide"
```

---

## Task 7: Add "Run with Docker" section to README

A short subsection right above the existing Laragon-based section, so newcomers default to the parity-with-prod path.

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Insert the new section**

Locate the heading `## Getting started (local development with Laragon)` (around line 74) and insert this block **immediately before** it:

```markdown
## Getting started with Docker (recommended for SiteGround parity)

This stack mirrors SiteGround shared hosting — Apache + mod_php + MySQL, with `file` cache/session and `sync` queue. Use it locally so routing and `.htaccess` bugs surface here, not after upload.

### Prerequisites

- Docker Engine 24+ and Docker Compose v2.

### Bring it up

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Then open `http://localhost:8080` and the Filament admin at `http://localhost:8080/admin`.

### Notes

- Override the host port via `APP_PORT` in `.env` (e.g. `APP_PORT=80`).
- The `app` container runs as your host user (UID/GID 1000 by default — override with `WWWUSER` / `WWWGROUP` build args if your host user differs).
- MySQL data persists in the named volume `cars-mysql-data`; `docker compose down` keeps it, `docker compose down -v` wipes it.
- Re-run `docker compose exec app composer install` after pulling changes that touch `composer.json` / `composer.lock`.

---

```

(The trailing `---` is the existing horizontal rule that separates major sections in this README.)

- [ ] **Step 2: Verify insertion**

Run: `grep -n "^## Getting started" README.md`
Expected: two lines, with "Getting started with Docker" appearing **before** "Getting started (local development with Laragon)".

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(readme): add Docker quickstart section"
```

---

## Task 8: End-to-end acceptance verification

This task runs the spec's acceptance criteria as a single end-to-end check. No new files. If any step fails, treat it as a bug to fix before considering the work done.

**Files:** none (verification only).

- [ ] **Step 1: Clean build**

Run: `docker compose build --no-cache`
Expected: build succeeds.

(Spec acceptance criterion 1: `docker compose build` succeeds from a clean clone.)

- [ ] **Step 2: Bring services up and wait for healthy MySQL**

Run: `docker compose up -d`
Then: `docker compose ps`
Expected: `mysql` shows `(healthy)`; `app` shows `Up`.

If `mysql` stays `(starting)` longer than ~30s, run `docker compose logs mysql` and inspect for credential or volume errors.

(Spec acceptance criterion 2.)

- [ ] **Step 3: Run first-time setup commands**

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Expected: each succeeds; `migrate --seed` produces output ending with seed-class summaries (`CarMakeSeeder`, `FilamentAdminUserSeeder`).

- [ ] **Step 4: Verify HTTP root**

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/`
Expected: `200` (Laravel welcome) or `302` (redirect — also acceptable).

(Spec acceptance criterion 3, part 1.)

- [ ] **Step 5: Verify Filament admin and `.htaccess` rewrite**

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/admin/login`
Expected: `200`.

A `404` here means `mod_rewrite` is not active or `AllowOverride` was not honored — i.e. `public/.htaccess` was ignored. That's the parity-failure mode this whole stack is designed to prevent.

(Spec acceptance criteria 3 part 2 + 4.)

- [ ] **Step 6: Verify host file ownership**

Trigger a request that writes a log:

```bash
curl -s -o /dev/null http://localhost:8080/
ls -l storage/logs/laravel.log 2>/dev/null || echo "no log yet"
```

If a log exists, expected: owner is your host user (`$USER`), not `root`.

(Spec acceptance criterion 5.)

- [ ] **Step 7: Verify MySQL persistence**

```bash
docker compose exec mysql mysql -u"$(grep ^DB_USERNAME .env | cut -d= -f2)" -p"$(grep ^DB_PASSWORD .env | cut -d= -f2)" -e "SELECT COUNT(*) AS car_makes FROM \`$(grep ^DB_DATABASE .env | cut -d= -f2)\`.car_makes;"
docker compose down
docker compose up -d
# Wait for mysql healthy, then:
docker compose exec mysql mysql -u"$(grep ^DB_USERNAME .env | cut -d= -f2)" -p"$(grep ^DB_PASSWORD .env | cut -d= -f2)" -e "SELECT COUNT(*) AS car_makes FROM \`$(grep ^DB_DATABASE .env | cut -d= -f2)\`.car_makes;"
```

Expected: same count both times (data preserved across down/up).

(Spec acceptance criterion 6.)

- [ ] **Step 8: Verify doc and config invariants**

```bash
grep -n "CACHE_STORE=file" DEPLOYMENT.md
grep -n "pecl install redis\|docker-php-ext-enable redis" Dockerfile || echo "OK: no redis lines"
test ! -d docker/nginx && echo "OK: docker/nginx absent" || echo "FAIL: docker/nginx still exists"
```

Expected:
- `DEPLOYMENT.md` shows the `CACHE_STORE=file` line.
- "OK: no redis lines" prints (no redis install in Dockerfile).
- "OK: docker/nginx absent" prints.

(Spec acceptance criteria 7, 8, 9.)

- [ ] **Step 9: No-op commit boundary**

This task does not change files. If everything above passed, no commit is needed. If you had to fix something during verification, commit it as a separate `fix(...)` commit before marking complete.

---

## Self-Review

**Spec coverage** — every spec section has at least one task:
- Goal & constraints → embedded in plan header and Task 1's `AllowOverride` justification.
- Services (`app`, `mysql`) → Task 2 (Dockerfile), Task 4 (compose).
- File table → Task 1 (vhost create), Task 2 (Dockerfile rewrite), Task 3 (nginx delete), Task 4 (compose rewrite), Task 5 (`.env` + `.env.example`), Task 6 (DEPLOYMENT.md), Task 7 (README).
- Apache vhost → Task 1.
- docker-compose shape → Task 4.
- Local workflow → Task 7 (in README) + Task 8 (executes it end-to-end).
- Best-practice rationale → captured inline in the relevant task (Apache override, UID/GID, anonymous volumes, healthcheck, port 8080).
- Out of scope → no tasks added (correct — the whole point is to *not* do them).
- Acceptance criteria 1–9 → Task 8.
- Risks → addressed: doc note about file vs database driver migration is implicit in the spec; port override is documented in README.

**Placeholder scan** — no TBD/TODO/"add appropriate" patterns. Every code/config block is concrete. Verification commands have explicit expected output.

**Type/identifier consistency** — `cars-mysql-data` volume, `cars-net` network, `cars-images-api/app` image, `WWWUSER`/`WWWGROUP` build args, `APP_PORT`/`FORWARD_DB_PORT` env vars are spelled the same in every task that references them.

No issues found.
