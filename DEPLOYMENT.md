# Deployment Guide – SiteGround (cars-search.artworkwebsite.com)

This document describes how to deploy the `cars-images-api` Laravel app to **SiteGround** under the subdomain:

- **Domain:** `cars-search.artworkwebsite.com`
- **SiteGround path:** `www/cars-search.artworkwebsite.com/public_html`
- **GitHub repo:** `https://github.com/RonaldAllanRivera/cars-images-api.git`

**Deployment model:** the code lives on GitHub. You `git clone` it onto SiteGround over SSH, then `git pull` for every later update. There is no SiteGround Git integration or CI/CD — every deploy is a manual SSH session.

This guide is written for an **Ubuntu** workstation. SiteGround's own SSH tutorial covers PuTTY on Windows; Section 2 below is the Ubuntu (OpenSSH) equivalent.

---

## 1. Before you start

### 1.1. Push your code to GitHub

SiteGround pulls the app **from GitHub**, so GitHub must hold the code you want to deploy. From your Ubuntu machine, in the project directory:

```bash
git status            # confirm the branch and that nothing important is uncommitted
git push origin main
```

If `git push` asks for a username/password or fails with `could not read Username`, your machine has no GitHub credentials yet. Pick one:

- **Personal Access Token (HTTPS):** create a token at GitHub → Settings → Developer settings → Personal access tokens, then use it as the password when `git push` prompts. Install `git-credential-libsecret` (or run `git config --global credential.helper store`) so you are not asked every time.
- **SSH (recommended if you also use the key below):** add an SSH public key to GitHub → Settings → SSH and GPG keys, then switch the remote:
  ```bash
  git remote set-url origin git@github.com:RonaldAllanRivera/cars-images-api.git
  git push origin main
  ```

Do not continue until `git push` succeeds and the GitHub repo shows your latest commit.

### 1.2. Make sure SiteGround runs PHP 8.3 or newer

This app **requires PHP 8.3+** (`composer.lock` pins packages that do not run on 8.2). If SiteGround serves an older version, `composer install` will fail.

In **Site Tools → Devs → PHP Manager**, set the PHP version for `cars-search.artworkwebsite.com` to **8.3 (or newer)**. If the site uses "Managed PHP", switch to "Manually" and choose 8.3+.

### 1.3. Collect what you will need

Have these ready before connecting:

- **SiteGround SSH details** — hostname, username, and port (SiteGround uses port **18765**). Found in **Site Tools → Devs → SSH Keys Manager**.
- **A production MySQL database** — create one in **Site Tools → Site → MySQL** and note the database name, username, password, and host.

---

## 2. Connecting to SiteGround via SSH (from Ubuntu)

SiteGround's PuTTY tutorial is Windows-only. On Ubuntu you use the built-in OpenSSH client — no PuTTY, no PuTTYgen, no key conversion.

### 2.1. Create an SSH key in SiteGround

1. Open **Site Tools → Devs → SSH Keys Manager**.
2. Click **Create** / **Generate**, give the key a name (e.g. `ubuntu-cars`), optionally set a passphrase, and create it.
3. **Download the private key** to your Ubuntu machine. Save it as `~/.ssh/siteground`.
4. On the same page, note the **Hostname**, **Username**, and **Port** (`18765`) shown for SSH access.

> Prefer to keep the private key on your machine only? Instead generate the key locally with
> `ssh-keygen -t ed25519 -f ~/.ssh/siteground -C "ubuntu-cars"`, then **Import** the contents of
> `~/.ssh/siteground.pub` into the SSH Keys Manager. Either approach works.

### 2.2. Install the private key on Ubuntu

OpenSSH refuses private keys with loose permissions, so lock the file down:

```bash
mkdir -p ~/.ssh
mv ~/Downloads/siteground ~/.ssh/siteground   # adjust to wherever you saved it
chmod 700 ~/.ssh
chmod 600 ~/.ssh/siteground
```

### 2.3. Connect

```bash
ssh -i ~/.ssh/siteground -p 18765 YOUR_SG_USERNAME@YOUR_SG_HOSTNAME
```

Replace `YOUR_SG_USERNAME` and `YOUR_SG_HOSTNAME` with the values from Step 2.1. Accept the host fingerprint the first time. If you set a passphrase, enter it when prompted.

### 2.4. Optional: add an SSH alias

So you can just type `ssh siteground`, add this to `~/.ssh/config` on Ubuntu:

```text
Host siteground
    HostName YOUR_SG_HOSTNAME
    User YOUR_SG_USERNAME
    Port 18765
    IdentityFile ~/.ssh/siteground
```

Then connect with:

```bash
ssh siteground
```

All commands in the rest of this guide are run **on SiteGround**, inside that SSH session.

---

## 3. Recommended directory layout

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

## 4. One-time initial deployment

All commands below are run **on SiteGround**, in the SSH session opened in Section 2.

### 4.1. Go to the subdomain base directory

```bash
cd ~/www/cars-search.artworkwebsite.com
```

### 4.2. Clone the GitHub repository

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

> **Private repository?** If the GitHub repo is private, an unauthenticated `git clone` over HTTPS will fail. Either make the repo public, or create a read-only **deploy key** (a separate SSH key added to the repo on GitHub) and clone with the `git@github.com:...` URL.

> **Alternative (directly into `public_html`):** If you deliberately want the repository files to live **inside** `public_html` instead of using the recommended layout above, you can run `git clone` with `.` (dot) as the target directory. This is less ideal for security, but works on shared hosting.

From inside `public_html`:

```bash
cd ~/www/cars-search.artworkwebsite.com/public_html

# Make sure public_html is empty or only has the default placeholder
rm Default.html  # or mv Default.html ../Default.html.bak

# Clone the repo directly into the current folder
git clone https://github.com/RonaldAllanRivera/cars-images-api.git .
```

### 4.3. Configure the document root

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

Alternatively, if you cloned the repository **directly into `public_html`** (so the Laravel project root is `~/www/cars-search.artworkwebsite.com/public_html` and the front controller is `public_html/public/index.php`), use this `.htaccess` in `public_html`:

```apache
# ~/www/cars-search.artworkwebsite.com/public_html/.htaccess

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Let Laravel handle every Livewire endpoint explicitly.
    #
    # Livewire 4 serves its routes from an APP_KEY-derived prefix
    # (e.g. /livewire-c3b9adb8/livewire.js, /livewire-c3b9adb8/update)
    # rather than the fixed /livewire/ of Livewire 3. The prefix differs
    # per environment because the APP_KEY does, so this rule MUST stay a
    # pattern — never hardcode the prefix you see locally.
    RewriteCond %{REQUEST_URI} ^/livewire(-[A-Za-z0-9]+)?/ [NC]
    RewriteRule ^ public/index.php [L]

    # Don't rewrite if already under /public
    RewriteCond %{REQUEST_URI} !^/public/

    # Send everything else into the Laravel public/ folder
    RewriteRule ^(.*)$ /public/$1 [L,QSA]
</IfModule>
```

This makes:

- `https://cars-search.artworkwebsite.com/` serve `public/index.php` without exposing `/public` in the URL.
- Livewire's JS asset and its `update` / `upload-file` endpoints go through Laravel instead of being treated as missing static files, avoiding the 404/403 errors that break the Filament login.

> **Livewire 4 changed this path.** Under Livewire 3 the asset was always at `/livewire/livewire.js`. Livewire 4 derives the whole route prefix from `APP_KEY`, so locally it might be `/livewire-c3b9adb8/livewire.js` while production is something else entirely. Verify the real value after deploying with `php artisan route:list | grep livewire`, and confirm the URL the login page references actually returns HTTP 200. If you ever rotate `APP_KEY`, the prefix changes with it.

> **Laravel `public/.htaccess`** – The Laravel project already includes an `.htaccess` file inside the `public/` directory with the standard rewrite rules that send all non-existing files/directories to `index.php`. On SiteGround you normally **leave this file as-is** – just make sure it exists after deployment.

### 4.4. Verify the PHP version, then install Composer dependencies

First confirm the SSH session is using PHP 8.3+ (see Section 1.2):

```bash
php -v
```

If it reports 8.2 or lower, fix the PHP version in **Site Tools → Devs → PHP Manager** before continuing — `composer install` will otherwise fail.

Then install dependencies:

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

composer install --no-dev --optimize-autoloader
```

If `composer` is not on your PATH, you may need to use the full path provided by SiteGround (for example):

```bash
php -d memory_limit=-1 ~/bin/composer.phar install --no-dev --optimize-autoloader
```

Adjust the command based on how Composer is installed on your SiteGround account.

### 4.5. Create and configure the `.env` file

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

# Caching / queueing (simple defaults — no queue worker or Redis needed)
QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
```

`.env.example` already ships sensible defaults for the Wikimedia and CSV-import settings. One worth checking: `WIKIMEDIA_USER_AGENT` should identify the site with a real contact (a URL and/or email) — Wikimedia asks bulk readers to be contactable.

Save and close the file when done.

### 4.6. Set correct file permissions

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

### 4.7. Create the storage symlink

Ensure public access to files stored under `storage/app/public`:

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

php artisan storage:link
```

### 4.8. Run database migrations and seeders

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

### 4.9. Optimize the application for production

```bash
cd ~/www/cars-search.artworkwebsite.com/cars-images-api

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

At this point, visiting `https://cars-search.artworkwebsite.com` should load the application and the Filament admin panel at `/admin`.

### 4.10. Ensure Filament admin access (User model)

Filament requires your authenticated user model to explicitly allow access to the admin panel. In this project, the `User` model implements `FilamentUser` and defines `canAccessPanel()`.

In `app/Models/User.php`:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    // ...

    public function canAccessPanel(Panel $panel): bool
    {
        return true; // allow all users, or add your own logic here
    }
}
```

If this method is missing or returns `false`, logging in at `/admin/login` will authenticate the user but redirect to a **`403 | FORBIDDEN`** page instead of the dashboard.

---

## 5. Setting up Laravel scheduler (optional)

This app does **not** require a queue worker or cron to run — searches execute synchronously and the CSV bulk feature is paced manually from the admin UI (`QUEUE_CONNECTION=sync`).

If you later add scheduled tasks or queued jobs, configure a cron job in **Site Tools → Devs → Cron Jobs** that runs every minute:

```bash
* * * * * php /home/YOUR_SG_USERNAME/www/cars-search.artworkwebsite.com/cars-images-api/artisan schedule:run >> /home/YOUR_SG_USERNAME/laravel-schedule.log 2>&1
```

Replace `YOUR_SG_USERNAME` with your actual SiteGround username.

If you later change `QUEUE_CONNECTION` from `sync` to `database`, also schedule a queue worker (e.g. `php artisan queue:work --stop-when-empty` driven by cron).

---

## 6. Updating the application (subsequent deploys)

When you push new code to GitHub and want to update the site:

1. **On Ubuntu** — push your latest commits:

   ```bash
   git push origin main
   ```

2. **SSH into SiteGround** and go to the project:

   ```bash
   ssh siteground          # or: ssh -i ~/.ssh/siteground -p 18765 USER@HOST
   cd ~/www/cars-search.artworkwebsite.com/cars-images-api
   ```

3. Pull the latest changes:

   ```bash
   git pull origin main
   ```

4. Install/update Composer dependencies:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

5. Run any new migrations:

   ```bash
   php artisan migrate --force
   ```

6. Rebuild caches (good practice after config or route changes):

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

7. (Optional) Clear any old caches before re-caching if you run into issues:

   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan cache:clear
   ```

---

## 7. Troubleshooting tips

- **Blank page or 500 error**
  - Check `storage/logs/laravel.log` for detailed error messages:

    ```bash
    cd ~/www/cars-search.artworkwebsite.com/cars-images-api
    tail -f storage/logs/laravel.log
    ```

- **`composer install` fails with a PHP version error**
  - The app needs PHP 8.3+. Check `php -v`, and set the version in **Site Tools → Devs → PHP Manager** (Section 1.2).

- **`Permission denied (publickey)` when connecting via SSH**
  - Confirm `chmod 600 ~/.ssh/siteground`, that you passed `-i ~/.ssh/siteground` and `-p 18765`, and that the key exists in **Site Tools → Devs → SSH Keys Manager**.

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

This guide should be all you need to deploy and maintain the `cars-images-api` project on SiteGround under `cars-search.artworkwebsite.com`, from an Ubuntu workstation, following Laravel best practices for a shared-hosting environment.
