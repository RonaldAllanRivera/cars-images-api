## Cars Images API (Wikimedia + Filament 4)

Cars Images API is a Laravel 12 + Filament 4 admin panel that integrates with **Wikimedia Commons** to search, cache, and manage high‑resolution **car images**.

It is designed as an internal tool and portfolio project to demonstrate:

- Clean Laravel backend architecture.
- Modern Filament 4 admin UI.
- Careful use of external APIs with caching and reuse of results.

---

## Features

- **Car-focused Wikimedia search**
  - Query Wikimedia Commons for images by make, model, year range, color, transmission, and transparent background.
  - Multi‑year searches: one request per year in the range.
- **Dynamic search form**
  - Make and model are linked: when you select a make, the model dropdown updates with relevant models.
  - Popular makes and models preconfigured for fast searches.
- **Caching and reuse**
  - Searches are stored in `car_searches` and associated images in `car_images`.
  - Identical completed searches are reused instead of hitting Wikimedia again.
- **Result quality filter**
  - Lightweight filter that tries to drop obvious non‑car images (e.g. flowers / plants) using image title, description, and categories.
- **Filament admin experience**
  - Dedicated navigation group for Cars.
  - Car Searches and Car Images tables with sortable, searchable columns.
  - Tables default to **100 rows per page** for efficient review.

---

## Tech stack

- **Backend**: Laravel 12 (PHP 8.2+)
- **Admin UI**: Filament 4 panel
- **External API**: MediaWiki / Wikimedia Commons
- **Database**: MySQL (Laragon in local dev)
- **Storage**: Laravel `storage` with a dedicated `cars` disk for future downloads

---

## Architecture overview

- **Wikimedia client** (`App\Services\Images\WikimediaClient`)
  - Wraps MediaWiki API calls.
  - Builds queries with make, model, year, color, transmission, and filters non‑car results.
  - Caches results per (make, model, year, color, transmission, transparent) combination.

- **Search service** (`App\Services\Images\CarImageSearchService`)
  - Coordinates multi‑year searches.
  - Normalizes year ranges (handles reversed from/to values).
  - Reuses existing completed searches when parameters match.

- **Jobs**
  - `RunCarSearchJob`, `FetchWikimediaCarImagesForYearJob`, `DownloadCarImagesJob` implemented for future asynchronous processing.
  - In local development, searches currently run synchronously for easier debugging.

- **Filament resources**
  - `CarSearchResource` – search form, search history, status, and related images.
  - `CarImageResource` – global view of all cached images.

For more detail, see `PLAN.md` and `CHAT.md` in the project root.

---

## Getting started (local development with Laragon)

### Prerequisites

- PHP 8.2+
- Composer
- MySQL (e.g. via Laragon)
- Node.js (optional, only if you plan to customize frontend assets)

### 1. Clone the repository

Clone into your Laragon `www` directory:

```bash
cd C:\laragon\www
git clone <YOUR_REPO_URL> cars-images-api
cd cars-images-api
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Configure environment

Copy `.env.example` to `.env` (or bring over your existing `.env`):

```bash
cp .env.example .env
```

Update `.env` to match your local database and app URL. Example:

```env
APP_URL=http://cars-images-api.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cars-images-api
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=sync

WIKIMEDIA_BASE_URL=https://commons.wikimedia.org/w/api.php
WIKIMEDIA_TIMEOUT=10
WIKIMEDIA_RETRY_TIMES=3
WIKIMEDIA_RETRY_SLEEP_MS=200
WIKIMEDIA_USER_AGENT="CarsImagesApi/1.0 (Laravel)"
WIKIMEDIA_CACHE_TTL=3600
```

Then generate the app key (only needed once per environment):

```bash
php artisan key:generate
```

### 4. Run migrations and seed admin user

```bash
php artisan migrate --seed
```

> The seeder should create a Filament admin user you can log in with.

### 5. Create storage symlink

```bash
php artisan storage:link
```

### 6. Serve the app

With Laragon you can visit:

- Filament admin: `http://cars-images-api.test/admin`
- Car Image Searches: `http://cars-images-api.test/admin/car-searches`

---

## Setting up on a new PC (using this repo as the main source)

When you move to a new machine, treat this repository as the single source of truth:

1. **Ensure your work is pushed from the old PC**
   - Commit all changes.
   - Push to your remote (e.g. GitHub, GitLab):

     ```bash
     git add .
     git commit -m "chore: sync local changes"
     git push origin main
     ```

2. **On the new PC, clone the repo**

   ```bash
   cd C:\laragon\www
   git clone <YOUR_REPO_URL> cars-images-api
   cd cars-images-api
   ```

3. **Install dependencies and configure `.env`**
   - Repeat steps from **Getting started**:
     - `composer install`
     - Copy or recreate `.env`.
     - `php artisan key:generate` (if needed).

4. **Recreate the database schema and admin user**

   ```bash
   php artisan migrate --seed
   ```

5. **Recreate the storage symlink**

   ```bash
   php artisan storage:link
   ```

6. **Confirm the Git remote**

   ```bash
   git remote -v
   ```

   Make sure it points to your main hosted repository (the same URL you cloned from). This way, this new machine is now your primary local clone.

After this, you can continue development on the new PC and push/pull as normal.

---

## Usage

### Running a car image search

1. Sign in to Filament at `/admin`.
2. Navigate to **Cars → Car Image Searches** and click **Create**.
3. Use the form:
   - Choose a **Make** – the **Model** dropdown automatically updates to show popular models for that make.
   - Set **From year / To year** (the service normalizes the range if they are reversed).
   - Optionally pick a **Color** and **Transmission**.
   - Toggle **Transparent background** and adjust **Images per year**.
4. Submit the form.
   - The app calls the Wikimedia API for each year, filters results to likely car images, stores them in `car_images`, and redirects to the search **View** page.
5. On the **View** page, scroll to the **Images** relation to see thumbnails and metadata.

### Browsing cached images

- Go to **Cars → Car Images** to browse all stored images.
- Both Car Searches and Car Images lists default to **100 rows per page**; use the pagination selector to change the page size.

### Search behaviour and caching

- The **first** time you run a make/model/year/color/transmission combination, the app calls Wikimedia and caches the results in the database.
- Subsequent searches with the **same parameters** reuse the existing completed `CarSearch` and its `CarImage` records instead of calling Wikimedia again.
- The Wikimedia client applies a simple filter to drop obvious non-car images (e.g. flowers / plants) using title, description, and category metadata.

---

## Roadmap / next steps

- Switch from synchronous to asynchronous queue processing in non‑local environments.
- Implement bulk download to the `cars` storage disk and CSV export of selected images.
- Add stronger rate limiting, richer logging/metrics, and automated tests.


