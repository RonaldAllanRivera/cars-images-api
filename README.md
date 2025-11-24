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
  - Flexible filters: "All models", "All colors", and "All transmissions" options let you widen or narrow searches quickly.
- **Dynamic search form**
  - Make and model are linked: when you select a make, the model dropdown updates with relevant models.
  - Popular makes and models preconfigured for fast searches.
- **Caching and reuse**
  - Searches are stored in `car_searches` and associated images in `car_images`.
  - Identical completed searches are reused instead of hitting Wikimedia again.
- **Result quality filter**
  - Lightweight filter that tries to drop obvious non‑car images (e.g. flowers / plants, or clearly non-car academic/journal pages) using image title, description, categories, and metadata.
- **Filament admin experience**
  - Dedicated navigation group for Cars.
  - Car Searches and Car Images tables with sortable, searchable columns and default **100 rows per page** for efficient review.
  - Per-row and bulk **Delete** actions for images, a **Refresh from Wikimedia** action on each search to clear images + cache and re-run with the latest filters, and a fast image **Preview** modal with a direct **Download** button that streams the image via an internal endpoint and updates the `download_status` badge in real time.
  - Bulk **Download selected** action on image tables that streams all selected images as a single ZIP archive with unique filenames to your local machine.

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
   - Choose a **Make** – the **Model** dropdown automatically updates to show popular models for that make, with an **All models** option to search across models.
   - Set **From year / To year** (the service normalizes the range if they are reversed).
   - Optionally pick a **Color** and **Transmission**, or leave them on **All colors** / **All transmissions** to avoid filtering by those fields.
   - Toggle **Transparent background** and adjust **Images per year**.
4. Submit the form.
   - The app calls the Wikimedia API for each year, filters results to likely car images, stores them in `car_images`, and redirects to the search **View** page.
5. On the **View** page, scroll to the **Images** relation to see thumbnails and metadata.

### Refreshing a search from Wikimedia

- From a search view page (**Cars → Car Image Searches → View**), use **Refresh from Wikimedia** in the header actions to:
  - Delete existing images for that search.
  - Clear cached Wikimedia responses for its years.
  - Re-run the search synchronously using the current filters.

### Cleaning up incorrect images

- From **Cars → Car Images**, use the per-row **Delete** action or the bulk **Delete selected** action to remove bad images.
- From a specific search's **Images** relation, you can also delete individual or multiple images using the same delete actions.

### Previewing and downloading images

- From **Cars → Car Images** or a search's **Images** relation:
  - Click a thumbnail or the **Preview** action to open a modal with a larger image (up to ~400×400), source URL, and title.
  - Use the **Download** button in the modal footer to download the full image via an internal download endpoint. When the download succeeds, the **Download status** badge for that image flips to a green `downloaded` state automatically, without needing to refresh the page.
  - To download many images at once, select them using the table checkboxes and use the **Download selected** bulk action. The app streams a ZIP file containing the selected images to your browser; the more images you select, the longer the ZIP creation and download will take.

### Browsing cached images

- Go to **Cars → Car Images** to browse all stored images.
- Both Car Searches and Car Images lists default to **100 rows per page**; use the pagination selector to change the page size.

### Search behaviour and caching

- The **first** time you run a make/model/year/color/transmission combination, the app calls Wikimedia and caches the results in the database.
- Subsequent searches with the **same parameters** reuse the existing completed `CarSearch` and its `CarImage` records instead of calling Wikimedia again.
- The Wikimedia client applies a lightweight filter to drop obvious non-car images (e.g. flowers / plants, or clearly non-car academic/journal pages) using title, description, categories, and other metadata.
- Using **Refresh from Wikimedia** invalidates both the cached images and the underlying Wikimedia cache for that search's years, so new results are fetched with the current filters.

---

## Roadmap / next steps

- Switch from synchronous to asynchronous queue processing in non‑local environments.
- Implement bulk download to the `cars` storage disk and CSV export of selected images.
- Add stronger rate limiting, richer logging/metrics, and automated tests.
- Explore optional AI-based filtering for ambiguous results (see `PLAN.md` section on AI-based filtering).


