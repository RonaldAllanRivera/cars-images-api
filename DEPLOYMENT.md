# Deployment Guide – SiteGround (cars-search.artworkwebsite.com)

This document describes how to deploy the `cars-images-api` Laravel app to **SiteGround** under the subdomain:

- **Domain:** `cars-search.artworkwebsite.com`
- **SiteGround path:** `www/cars-search.artworkwebsite.com/public_html`

You will deploy directly from the GitHub repo:

- `https://github.com/RonaldAllanRivera/cars-images-api.git`

You already know how to connect via SSH, so the steps below assume you are logged into your SiteGround account over SSH.

---

## 1. Recommended directory layout

**Goal:** Keep the Laravel application *outside* the public web root, and expose **only** the `public/` directory.

Recommended structure under `~/www/cars-search.artworkwebsite.com`:

```text
~/www/cars-search.artworkwebsite.com/
  cars-images-api/           # Laravel project root (cloned from GitHub)
    public/                  # Document root that should be exposed to the web
  public_html -> cars-images-api/public   # Either docroot or a symlink to /public
```

You can achieve this in one of two ways:

- **Option A (preferred):** In SiteGround Site Tools, set the subdomain document root to:
  - `~/www/cars-search.artworkwebsite.com/cars-images-api/public`
- **Option B (symlink):** Keep the document root as `~/www/cars-search.artworkwebsite.com/public_html` and make `public_html` a **symlink** to the Laravel `public/` directory.

The commands below are written assuming this recommended layout.

---

## 2. One-time initial deployment

All commands below are run after connecting to SiteGround over SSH.

### 2.1. Go to the subdomain base directory

```bash
cd ~/www/cars-search.artworkwebsite.com
```

### 2.2. Clone the GitHub repository

Clone the app into a folder called `cars-images-api`:

```bash
git clone https://github.com/RonaldAllanRivera/cars-images-api.git cars-images-api
```

If you need to deploy a specific branch (e.g. `main`):

```bash
cd cars-images-api
git checkout main
cd ..
```

> **Alternative (directly into `public_html`):** If you deliberately want the repository files to live **inside** `public_html` instead of using the recommended layout above, you can run `git clone` with `.` (dot) as the target directory. This is less ideal for security, but works on shared hosting.

From inside `public_html`:

```bash
cd ~/www/cars-search.artworkwebsite.com/public_html

# Make sure public_html is empty or only has the default placeholder
rm Default.html  # or mv Default.html ../Default.html.bak

# Clone the repo directly into the current folder
git clone https://github.com/RonaldAllanRivera/cars-images-api.git .
```

### 2.3. Configure the document root

#### Option A – Change docroot in SiteGround UI (preferred)

1. In **Site Tools → Domains → Subdomains**, edit the `cars-search.artworkwebsite.com` subdomain.
2. Set the **Document Root** to:

   ```text
   /home/YOUR_SG_USERNAME/www/cars-search.artworkwebsite.com/cars-images-api/public
   ```

3. Save changes.

> Replace `YOUR_SG_USERNAME` with your actual SiteGround system username.

#### Option B – Symlink `public_html` to `public/`

Use this if you must keep the docroot as `~/www/cars-search.artworkwebsite.com/public_html`.

```bash
cd ~/www/cars-search.artworkwebsite.com

# Optional: backup existing public_html if it contains files you care about
mv public_html public_html_backup_$(date +%Y%m%d%H%M%S)

# Create a symlink so public_html points to Laravel's public/ directory
ln -s cars-images-api/public public_html
```

After this, requests to `cars-search.artworkwebsite.com` will be served from `cars-images-api/public`.

#### .htaccess notes for SiteGround / Apache

In the recommended setups above (changing the document root to `cars-images-api/public` **or** using a symlink), you usually **don’t need a custom `.htaccess` in `public_html`**. Apache will serve the `public/` directory directly, and Laravel’s own `public/.htaccess` will handle all routing.

If you prefer to keep the subdomain document root as `public_html` and your Laravel app in a subfolder (for example `public_html/cars-images-api/public`), you can instead use a `.htaccess` file in `public_html` (based on SiteGround’s KB) to internally rewrite all requests into that subfolder:

```apache
# ~/www/cars-search.artworkwebsite.com/public_html/.htaccess

RewriteEngine On

# Prevent rewrite loops when already inside the Laravel public/ folder
RewriteCond %{REQUEST_URI} !^/cars-images-api/public/

# Send everything to the Laravel public/ directory
RewriteRule ^(.*)$ /cars-images-api/public/$1 [L]
```

With this configuration, the URL in the browser stays as `https://cars-search.artworkwebsite.com/...`, but Apache serves files from `cars-images-api/public`.

> **Laravel `public/.htaccess`** – The Laravel project already includes an `.htaccess` file inside the `public/` directory with the standard rewrite rules that send all non-existing files/directories to `index.php`. On SiteGround you normally **leave this file as-is** – just make sure it exists after deployment.

### 2.4. Install PHP dependencies (Composer)

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

composer install --no-dev --optimize-autoloader
```

If `composer` is not on your PATH, you may need to use the full path provided by SiteGround (for example):

```bash
php -d memory_limit=-1 ~/bin/composer.phar install --no-dev --optimize-autoloader
```

Adjust the command based on how Composer is installed on your SiteGround account.

### 2.5. Create and configure the `.env` file

Copy the example configuration:

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

cp .env.example .env
```

Generate the application key:

```bash
php artisan key:generate --force
```

Edit `.env` with your production settings (database, URL, etc.):

```bash
nano .env
```

Recommended key values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cars-search.artworkwebsite.com

# Database (replace with your actual SG database name, user, password)
DB_CONNECTION=mysql
DB_HOST=YOUR_DB_HOST        # e.g. 127.0.0.1 or SiteGround DB host
DB_PORT=3306
DB_DATABASE=YOUR_DB_NAME
DB_USERNAME=YOUR_DB_USER
DB_PASSWORD=YOUR_DB_PASSWORD

# Caching / queueing (simple defaults)
QUEUE_CONNECTION=sync
CACHE_DRIVER=file
SESSION_DRIVER=file
```

Save and close the file when done.

### 2.6. Set correct file permissions

Laravel needs write permissions on `storage` and `bootstrap/cache`.

From the project root:

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

chmod -R ug+rwx storage bootstrap/cache
```

If you prefer more granular settings, you can run:

```bash
find storage -type d -exec chmod 775 {} \;
find storage -type f -exec chmod 664 {} \;
find bootstrap/cache -type d -exec chmod 775 {} \;
find bootstrap/cache -type f -exec chmod 664 {} \;
```

### 2.7. Create the storage symlink

Ensure public access to files stored under `storage/app/public`:

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

php artisan storage:link
```

### 2.8. Run database migrations and seeders

Run migrations in production mode and seed the database (including car makes/models and admin user seeder that are registered in `DatabaseSeeder`):

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

php artisan migrate --force
php artisan db:seed --force
```

If you ever need to re-run just a specific seeder, you can use:

```bash
php artisan db:seed --class=CarMakeSeeder --force
php artisan db:seed --class=FilamentAdminUserSeeder --force
```

### 2.9. Optimize the application for production

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

At this point, visiting `https://cars-search.artworkwebsite.com` should load the application and the Filament admin panel at `/admin`.

---

## 3. Setting up Laravel scheduler (optional but recommended)

If you start using scheduled tasks or queued jobs in the future, configure a cron job in SiteGround.

In **Site Tools → Devs → Cron Jobs**, add a cron job that runs every minute:

```bash
* * * * * php /home/YOUR_SG_USERNAME/www/cars-search.artworkwebsite.com/cars-images-api/artisan schedule:run >> /home/YOUR_SG_USERNAME/laravel-schedule.log 2>&1
```

Replace `YOUR_SG_USERNAME` with your actual SiteGround username.

If you later change `QUEUE_CONNECTION` from `sync` to something like `database`, ensure your scheduled tasks or cron also run a queue worker (for example via a `queue:work` command inside `app/Console/Kernel.php`).

---

## 4. Updating the application (subsequent deploys)

When you push new code to GitHub and want to update the site:

1. SSH into SiteGround and go to the project:

   ```bash
   cd ~/www/cars-search.artworkwebsite.com/cars-images-api
   ```

2. Pull the latest changes from the branch you are using (e.g. `main`):

   ```bash
   git pull origin main
   ```

3. Install/update Composer dependencies:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. Run any new migrations:

   ```bash
   php artisan migrate --force
   ```

5. Rebuild caches (good practice after config or route changes):

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

6. (Optional) Clear any old caches before re-caching if you run into issues:

   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan cache:clear
   ```

---

## 5. Troubleshooting tips

- **Blank page or 500 error**
  - Check `storage/logs/laravel.log` for detailed error messages:

    ```bash
    cd ~/www/cars-search.artworkwebsite.com/cars-images-api
    tail -f storage/logs/laravel.log
    ```

- **Permissions issues / cannot write to storage**
  - Re-apply permissions:

    ```bash
    cd ~/www/cars-search.artworkwebsite.com/cars-images-api
    chmod -R ug+rwx storage bootstrap/cache
    ```

- **Wrong URL / redirects**
  - Confirm `APP_URL` in `.env` is exactly:

    ```env
    APP_URL=https://cars-search.artworkwebsite.com
    ```

- **Changes not showing**
  - Clear and rebuild caches:

    ```bash
    cd ~/www/cars-search.artworkwebsite.com/cars-images-api
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    php artisan cache:clear

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    ```

This guide should be all you need to deploy and maintain the `cars-images-api` project on SiteGround under `cars-search.artworkwebsite.com` via SSH, following Laravel best practices for a shared-hosting environment.
