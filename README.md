<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Cars Images API (Wikimedia + Filament 4)

This project is an internal **Cars Images API** built on **Laravel 12** and **Filament 4**. It integrates with **Wikimedia Commons** to search and cache high-resolution car images based on:

- **Make** and optional **model** (dynamic dropdowns)
- **FROM YEAR / TO YEAR** range (multiple years)
- Optional **color**
- Optional **transmission** (Automatic / Manual / CVT)
- Optional **transparent background** preference

For each year in the selected range, the system queries Wikimedia (up to a configurable number of images per year), stores normalized metadata in the database, and exposes the results through a Filament admin panel.

### Key features

- Filament 4 admin panel at `/admin`
- **Car Image Search** form with dynamic make/model selects, year range, color, transmission, transparent flag, and images-per-year
- Car image searches stored in `car_searches` with status tracking (`pending`, `running`, `completed`, `failed`)
- Image metadata stored in `car_images` (provider IDs, URLs, size, license, attribution, etc.)
- **DB-backed reuse** of identical searches to avoid hitting Wikimedia more than necessary
- Simple content filter that tries to drop obvious non-car images (e.g. flowers / plants)
- Configurable Wikimedia integration via `config/images.php` and `.env`
- Dedicated `cars` storage disk for future downloaded image files

### Technology stack

- Laravel 12 (PHP 8.2+)
- Filament 4 admin panel
- MediaWiki/Wikimedia Commons API
- MySQL (Laragon) for persistence

### Local setup (Laragon)

1. Clone the repository into your Laragon `www` directory, e.g. `C:\laragon\www\cars-images-api`.
2. Install dependencies:

   ```bash
   composer install
   ```

3. Configure `.env` (database, app URL, Wikimedia settings). Example:

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

4. Run migrations and seed the Filament admin user (if you have a seeder):

   ```bash
   php artisan migrate --seed
   ```

5. Ensure the storage symlink exists:

   ```bash
   php artisan storage:link
   ```

6. Serve the app (or let Laragon handle it) and visit:

   - Filament admin: `http://cars-images-api.test/admin`
   - Car Image Searches: `http://cars-images-api.test/admin/car-searches`

### Usage

#### Running a car image search

1. Sign in to Filament at `/admin`.
2. Navigate to **Cars → Car Image Searches** and click **Create**.
3. Use the form:
   - Choose a **Make** – the **Model** dropdown will automatically update to show popular models for that make.
   - Set **From year / To year** (the service normalizes the range if they are reversed).
   - Optionally pick a **Color** and **Transmission**.
   - Toggle **Transparent background** and adjust **Images per year**.
4. Submit the form.
   - The app calls the Wikimedia API for each year, filters results to likely car images, stores them in `car_images`, and redirects to the search **View** page.
5. On the **View** page, scroll to the **Images** section to see thumbnails and metadata.

#### Browsing cached images

- Go to **Cars → Car Images** to browse all stored images.
- Both Car Searches and Car Images lists default to **100 rows per page**; use the pagination selector to change the page size.

#### Search behaviour and caching

- The **first** time you run a make/model/year/color/transmission combination, the app calls Wikimedia and caches the results in the database.
- Subsequent searches with the **same parameters** reuse the existing completed `CarSearch` and its `CarImage` records instead of calling Wikimedia again.
- The Wikimedia client applies a simple filter to drop obvious non-car images (e.g. flowers / plants) using title, description, and category metadata.

### Current limitations / next steps

- Searches currently run synchronously (`QUEUE_CONNECTION=sync`). A background queue worker can be introduced later.
- Download and export flows (bulk download to `cars` disk, CSV export) are planned but not implemented yet.
- Rate limiting, richer logging, and automated tests are still to be added.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
