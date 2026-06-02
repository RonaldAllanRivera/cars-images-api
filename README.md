## Cars Images API (Wikimedia + Filament 4)

Cars Images API is a Laravel 12 + Filament 4 admin panel that integrates with **Wikimedia Commons** to search, cache, and manage high‑resolution **car images**. It supports both ad‑hoc single searches and **bulk CSV‑driven** harvesting of thousands of vehicles, with manual review and web‑optimized downloads.

It is designed as an internal tool and portfolio project to demonstrate:

- Clean Laravel backend architecture.
- Modern Filament 4 admin UI.
- Careful use of external APIs with caching, reuse, and rate‑limit etiquette.
- Pragmatic handling of real‑world data quality (relevance flagging, server‑side image optimization).

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
  - Non‑image files (PDFs, documents) returned by Wikimedia's File namespace are excluded by MIME type.
  - **Year‑relaxation fallback**: when a year‑specific search returns nothing, it retries once without the year so sparse models still return results.
- **Make‑relevance flagging**
  - Each stored image records whether the searched **make actually appears** in its title, description, or categories. The Results page shows a **"Make match"** badge (Confirmed / Not confirmed) and a filter to show only confirmed matches.
  - This surfaces a real Wikimedia data quirk: badge‑engineered or region‑specific models are filed under another make (e.g. the Acura CL is catalogued as a "Honda Accord"), so an "Acura" search may return the same car under "Honda". Nothing is hidden — you review and decide, with the borderline ones flagged for your eye.
- **CSV bulk search & download** (three‑page workflow under **Cars**)
  - **Upload CSV** of `Make, Model, Year, Transmission` rows; deduplicated by `(Year, Make, Model)`, capped per upload, stored as pending search queries.
  - **Search Queries**: review imported queries and run them manually — one at a time or in capped bulk batches — with a live loader. Wikimedia rate‑limit responses (429/403/503) auto‑pause the run and are logged.
  - **Results**: browse images, then **Download selected as ZIP**, **Download Confirmed as ZIP** (only images whose make matched — see make‑relevance flagging), or **Export selected as CSV** (manifest), with `YEAR MAKE MODEL.jpg` filenames and duplicate suffixes. Bulk ZIPs are capped (`CARS_BULK_DOWNLOAD_MAX_IMAGES`, default 100) since they are built synchronously — large sets are downloaded in batches.
- **Web‑optimized downloads**
  - Bulk ZIP images are resized server‑side (GD) to a configurable max width (default 1600px) and re‑encoded as JPEG — typically ~85% smaller than the originals. (Wikimedia blocks on‑demand thumbnail generation from server IPs, so resizing is done locally.)
- **Filament admin experience**
  - Dedicated navigation group for Cars.
  - Car Searches and Car Images tables with sortable, searchable columns and default **100 rows per page** for efficient review.
  - Per-row and bulk **Delete** actions for images, a **Refresh from Wikimedia** action on each search to clear images + cache and re-run with the latest filters, and a fast image **Preview** modal with a direct **Download** button that streams the image via an internal endpoint and updates the `download_status` badge in real time.
  - Bulk **Download selected** action on image tables that streams all selected images as a single ZIP archive with unique filenames to your local machine.
 - **Car make & model catalog**
   - Dedicated **Car Makes** admin page where you can define a make once and attach multiple models using a repeater.
   - These makes and models populate the options for the Car Image Search form, with sensible defaults seeded for common brands.

---

## Tech stack

- **Backend**: Laravel 12 (PHP 8.3+)
- **Admin UI**: Filament 4 panel
- **External API**: MediaWiki / Wikimedia Commons
- **Database**: MySQL 8 (Docker for local dev — see "Getting started with Docker" below)
- **Storage**: Laravel `storage` with a dedicated `cars` disk for downloads

> Kept current on the latest Laravel 12.x / Filament 4.x releases. Laravel 13 is
> not yet adopted because Filament 4 and Livewire 3 do not support it yet; the
> upgrade will follow once that ecosystem ships Laravel 13 compatibility.

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
  - `CarMakeResource` – catalog of car makes and their models, used to drive make/model dropdowns on the search form.
  - `UserResource` – simple admin user management (name, unique email, password) so you can add additional Filament admins from the UI.

For more detail, see `PLAN.md` and `CHAT.md` in the project root.

---

## Getting started with Docker (recommended for SiteGround parity)

This stack mirrors SiteGround shared hosting — Apache + mod_php + MySQL, with `file` cache/session and `sync` queue. Use it locally so routing and `.htaccess` bugs surface here, not after upload.

### Prerequisites

- Docker Engine 24+ and Docker Compose v2.

### Bring it up

```bash
cp .env.example .env
# Edit .env — set APP_NAME, DB_DATABASE, DB_USERNAME, DB_PASSWORD before continuing
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

## Getting started (local development with Laragon)

### Prerequisites

- PHP 8.3+ (required — `composer.lock` pins packages that need 8.3)
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

> The seeder creates a Filament admin user you can log in with, and also seeds a default list of **car makes and models** used by the Car Image Search form.

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

### CSV Bulk Search and Download

For bulk harvesting, use the three-page CSV workflow under the **Cars** navigation group:

1. **Upload CSV** (`/admin/csv-imports/create`) — drop in a CSV with columns `Make, Model, Year, Transmission`. Rows are deduplicated by `(Year, Make, Model)`. Uploads with more than `CSV_IMPORT_MAX_COMBOS` unique combos (default 1,000) are rejected; split the CSV externally first.
2. **Search Queries** (`/admin/search-queries`) — review the imported queries. Click `[Run]` per-row, or select multiple and click `[Run Selected]`. The bulk run caps at 50 queries or 50 seconds per click — click again to continue.
3. **Results** (`/admin/results`) — browse images from completed queries. Select images and use `[Download Selected as ZIP]` (renamed files inside) or `[Export Selected as CSV]` (manifest).

**Filename format:** `"YEAR MAKE MODEL.ext"` — e.g. `1997 Toyota RAV4.jpg`. Duplicates within an export get a numeric suffix: `1997 Toyota RAV4.jpg`, `1997 Toyota RAV4 2.jpg`.

**Wikimedia etiquette is on by default:** honest User-Agent with contact info, `maxlag=5`, 24h cache, 1-second throttle between bulk queries, exponential backoff on transient errors. Any 429/403/503 response auto-pauses the bulk loop and writes a `wikimedia_block_events` row.

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

### Managing car makes and models

- Navigate to **Cars → Car Makes** to manage the catalog of makes and models.
- When you create or edit a car make, you can add multiple models via the **Models** repeater field.
- The Car Image Search form will use these values as its make/model options (falling back to built-in defaults if the tables are empty).

### Managing admin users

- Navigate to **System → Admin Users** to manage Filament admin accounts.
- Use **Create** to add a new admin with name, email, and password. The password is stored securely using Laravel's hashed cast.
- When editing a user, leave the password blank to keep it unchanged, or enter a new password to update it.

### Refreshing or re-running a search

- From a search view page (**Cars → Car Image Searches → View**), use **Refresh from Wikimedia** in the header actions to:
  - Delete existing images for that search.
  - Clear cached Wikimedia responses for its years.
  - Re-run the search synchronously using the **current** filters (useful when filters are correct but you want a fresh set of results).
- To **search again with different filters**, click **Edit** on the search, adjust fields such as Make/Model, year range, Color, or Transmission, and click **Save**. After saving, the app deletes the old images for that search, re-runs the Wikimedia calls with the **updated** filters, and repopulates the Images relation with the new results.

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
- When you leave **Model**, **Color**, or **Transmission** on their **All ...** options, those fields are stored as `null` in the database, but Filament forms and tables always render them as `All` (for example, `All models`, `All colors`, `All transmissions`) so it is obvious that no filter was applied.
- The Wikimedia client applies a lightweight filter to drop obvious non-car images (e.g. flowers / plants, or clearly non-car academic/journal pages) using title, description, categories, and other metadata.
- Using **Refresh from Wikimedia** invalidates both the cached images and the underlying Wikimedia cache for that search's years, so new results are fetched with the current filters.

---

## Roadmap / next steps

- Switch from synchronous to asynchronous queue processing in non‑local environments.
- Implement bulk download to the `cars` storage disk and CSV export of selected images.
- Add stronger rate limiting, richer logging/metrics, and automated tests.
- Explore optional AI-based filtering for ambiguous results (see `PLAN.md` section on AI-based filtering).


