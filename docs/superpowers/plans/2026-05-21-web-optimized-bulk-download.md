# Web-Optimized Bulk Download ZIP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the bulk-download ZIP smaller by fetching width-capped Wikimedia thumbnail URLs (configurable, default 1600px) instead of full-resolution originals — no server-side image processing.

**Architecture:** A new `WikimediaThumbnailUrlBuilder` converts a full-resolution `upload.wikimedia.org` URL into a `{width}px` thumbnail URL, falling back to the input unchanged when it cannot. `BatchZipBuilder` fetches the thumbnail URL; if that fetch fails it retries the original so an image is never dropped. Width comes from a new `cars-images.download_max_width` config value.

**Tech Stack:** Laravel 12, PHPUnit 11, Laravel HTTP client (`Http::fake()` in tests).

**Working directory:** `/home/allan/code/laravel/cars-images-api`. **Branch:** `main`.

**Context the implementer must know:**
- `BatchZipBuilder::buildToFile()` currently fetches `$image->source_url` with a User-Agent header and returns the count of images written.
- `CarImage->source_url` is a full-resolution Wikimedia URL, e.g. `https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg`.
- The Docker stack is running; tests run via `docker compose exec app php artisan test`.
- **Docker note:** after editing a PHP file, run `docker compose restart app` before running tests — the dev container can serve a stale copy briefly otherwise.
- Commits use a `Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>` trailer.

---

## Task 1: Config value for download width

**Files:**
- Modify: `config/cars-images.php`
- Modify: `.env`
- Modify: `.env.example`

- [ ] **Step 1: Add the config key**

In `config/cars-images.php`, add this block inside the returned array (after the existing `bulk_run_*` entries, before the closing `];`):

```php
    /*
    |--------------------------------------------------------------------------
    | Bulk download image sizing
    |--------------------------------------------------------------------------
    |
    | Maximum width (in pixels) for images placed in the bulk-download ZIP.
    | Images are fetched from Wikimedia's thumbnail CDN at this width, so no
    | image processing happens on this server.
    |
    | Common choices:
    |   1280  - typical web content width, smallest files
    |   1600  - default; good size/quality balance
    |   1920  - full-HD, sharper but larger
    |
    | Future options to consider (not implemented):
    |   - a null value meaning "download originals, unoptimized"
    |   - a per-download width chosen in the UI
    |   - server-side WebP conversion for further savings
    |
    */
    'download_max_width' => env('CARS_DOWNLOAD_MAX_WIDTH', 1600),
```

- [ ] **Step 2: Add the env var to `.env` and `.env.example`**

Append to both files, near the other `CARS_*` entries:

```
CARS_DOWNLOAD_MAX_WIDTH=1600
```

- [ ] **Step 3: Verify**

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan tinker --execute="echo config('cars-images.download_max_width');"
```

Expected output: `1600`.

- [ ] **Step 4: Commit**

`.env` is gitignored — stage only the config file and `.env.example`.

```bash
git add config/cars-images.php .env.example
git commit -m "$(cat <<'EOF'
feat(config): add download_max_width for web-optimized bulk ZIP

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: WikimediaThumbnailUrlBuilder

**Files:**
- Create: `app/Services/Images/WikimediaThumbnailUrlBuilder.php`
- Test: `tests/Unit/Services/Images/WikimediaThumbnailUrlBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Images/WikimediaThumbnailUrlBuilderTest.php`:

```php
<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\WikimediaThumbnailUrlBuilder;
use PHPUnit\Framework\TestCase;

class WikimediaThumbnailUrlBuilderTest extends TestCase
{
    private WikimediaThumbnailUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new WikimediaThumbnailUrlBuilder();
    }

    public function test_builds_thumbnail_url_from_standard_commons_url(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg';

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1600px-Foo.jpg',
            $this->builder->forWidth($source, 1600),
        );
    }

    public function test_uses_the_requested_width(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Bar.png';

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Bar.png/1280px-Bar.png',
            $this->builder->forWidth($source, 1280),
        );
    }

    public function test_preserves_url_encoded_filename(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/4/47/2014_Show_%281999_Acura_NSX%29.jpg';

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/2014_Show_%281999_Acura_NSX%29.jpg/1600px-2014_Show_%281999_Acura_NSX%29.jpg',
            $this->builder->forWidth($source, 1600),
        );
    }

    public function test_returns_non_wikimedia_url_unchanged(): void
    {
        $source = 'https://example.com/images/car.jpg';

        $this->assertSame($source, $this->builder->forWidth($source, 1600));
    }

    public function test_returns_already_thumbnail_url_unchanged(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1280px-Foo.jpg';

        $this->assertSame($source, $this->builder->forWidth($source, 1600));
    }

    public function test_returns_svg_source_unchanged(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/4/47/Logo.svg';

        $this->assertSame($source, $this->builder->forWidth($source, 1600));
    }
}
```

- [ ] **Step 2: Run the test — verify it fails**

```bash
docker compose exec app php artisan test --filter=WikimediaThumbnailUrlBuilderTest
```

Expected: fails with "Class WikimediaThumbnailUrlBuilder not found".

- [ ] **Step 3: Implement the builder**

Create `app/Services/Images/WikimediaThumbnailUrlBuilder.php`:

```php
<?php

namespace App\Services\Images;

class WikimediaThumbnailUrlBuilder
{
    /**
     * Standard Wikimedia Commons original-file URL:
     *   https://upload.wikimedia.org/wikipedia/commons/{a}/{ab}/{file}
     *
     * The two path segments are an md5-hash prefix (1 char / 2 chars).
     * An already-thumbnailed URL has "thumb/" in that position and does
     * not match.
     */
    private const COMMONS_ORIGINAL_PATTERN =
        '#^(https://upload\.wikimedia\.org/wikipedia/commons)/([0-9a-f]/[0-9a-f]{2})/([^/]+)$#';

    /**
     * Build a width-capped Wikimedia thumbnail URL from a full-resolution
     * source URL. Returns the input unchanged when a thumbnail URL cannot
     * be safely derived (non-Wikimedia host, already a thumbnail, SVG, or
     * any unexpected path shape).
     */
    public function forWidth(string $sourceUrl, int $width): string
    {
        if (! preg_match(self::COMMONS_ORIGINAL_PATTERN, $sourceUrl, $matches)) {
            return $sourceUrl;
        }

        [, $base, $hash, $file] = $matches;

        // Wikimedia SVG thumbnails use a different "...px-Foo.svg.png"
        // pattern; SVG originals are tiny vectors, so leave them as-is.
        if (str_ends_with(strtolower($file), '.svg')) {
            return $sourceUrl;
        }

        return "{$base}/thumb/{$hash}/{$file}/{$width}px-{$file}";
    }
}
```

- [ ] **Step 4: Run the test — verify it passes**

```bash
docker compose restart app
docker compose exec app php artisan test --filter=WikimediaThumbnailUrlBuilderTest
```

Expected: 6/6 pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Images/WikimediaThumbnailUrlBuilder.php tests/Unit/Services/Images/WikimediaThumbnailUrlBuilderTest.php
git commit -m "$(cat <<'EOF'
feat(images): add WikimediaThumbnailUrlBuilder

Derives a {width}px Wikimedia thumbnail URL from a full-resolution
Commons URL. Returns the input unchanged for non-Wikimedia hosts,
already-thumbnailed URLs, and SVGs.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Fetch web-sized thumbnails in BatchZipBuilder

**Files:**
- Modify: `app/Services/Downloads/BatchZipBuilder.php`
- Test: `tests/Feature/Services/Downloads/BatchZipBuilderTest.php`

- [ ] **Step 1: Write the failing tests**

Add these two test methods to `tests/Feature/Services/Downloads/BatchZipBuilderTest.php` (inside the class, after the existing methods):

```php
    public function test_fetches_width_capped_wikimedia_thumbnail(): void
    {
        config(['cars-images.download_max_width' => 1600]);

        Http::fake([
            '*' => Http::response('IMG', 200),
        ]);

        $user = User::factory()->create();
        $search = CarSearch::create([
            'make' => 'Acura', 'model' => 'NSX',
            'from_year' => 1999, 'to_year' => 1999,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
        ]);
        $img = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Acura', 'model' => 'NSX',
            'year' => 1999, 'title' => 'A',
            'source_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg',
            'thumbnail_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg',
            'width' => 4000, 'height' => 3000, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
        $added = app(BatchZipBuilder::class)->buildToFile(collect([$img]), $tmpFile);
        @unlink($tmpFile);

        $this->assertSame(1, $added);

        Http::assertSent(function ($request) {
            return $request->url()
                === 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1600px-Foo.jpg';
        });
    }

    public function test_falls_back_to_original_when_thumbnail_fetch_fails(): void
    {
        config(['cars-images.download_max_width' => 1600]);

        $thumbUrl = 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1600px-Foo.jpg';
        $originalUrl = 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg';

        Http::fake([
            $thumbUrl => Http::response('boom', 500),
            $originalUrl => Http::response('ORIGINAL', 200),
        ]);

        $user = User::factory()->create();
        $search = CarSearch::create([
            'make' => 'Acura', 'model' => 'NSX',
            'from_year' => 1999, 'to_year' => 1999,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
        ]);
        $img = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Acura', 'model' => 'NSX',
            'year' => 1999, 'title' => 'A',
            'source_url' => $originalUrl,
            'thumbnail_url' => $originalUrl,
            'width' => 4000, 'height' => 3000, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
        $added = app(BatchZipBuilder::class)->buildToFile(collect([$img]), $tmpFile);

        $this->assertSame(1, $added);

        $zip = new ZipArchive();
        $zip->open($tmpFile);
        $this->assertSame('ORIGINAL', $zip->getFromName('1999 Acura NSX.jpg'));
        $zip->close();
        @unlink($tmpFile);
    }
```

- [ ] **Step 2: Run the tests — verify they fail**

```bash
docker compose exec app php artisan test --filter=BatchZipBuilderTest
```

Expected: the two new tests fail — `test_fetches_width_capped_wikimedia_thumbnail` fails the `assertSent` (the original URL is still fetched), and `test_falls_back_to_original_when_thumbnail_fetch_fails` fails because the thumbnail URL is never requested.

- [ ] **Step 3: Modify `BatchZipBuilder`**

Make four changes to `app/Services/Downloads/BatchZipBuilder.php`:

**3a.** Add the import near the top, with the other `use` statements:

```php
use App\Services\Images\WikimediaThumbnailUrlBuilder;
use Illuminate\Http\Client\Response;
```

**3b.** Add the new dependency to the constructor:

```php
    public function __construct(
        protected FilenameBuilder $filenames,
        protected WikimediaThumbnailUrlBuilder $thumbnailUrls,
    ) {
    }
```

**3c.** In `buildToFile()`, replace the per-image fetch. The current loop body starts with:

```php
        foreach ($images as $image) {
            /** @var CarImage $image */
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(30)
                ->get($image->source_url);

            if (! $response->successful()) {
                // Skip individual fetch failures rather than aborting the whole ZIP.
                continue;
            }
```

Replace that with:

```php
        $maxWidth = (int) config('cars-images.download_max_width', 1600);

        foreach ($images as $image) {
            /** @var CarImage $image */
            $response = $this->fetchImageBinary((string) $image->source_url, $userAgent, $maxWidth);

            if ($response === null) {
                // Skip individual fetch failures rather than aborting the whole ZIP.
                continue;
            }
```

(The `$maxWidth` line must be placed once, before the `foreach` — not inside it. Keep the existing `$userAgent`, `$usedNames`, `$baseCounters`, `$added` declarations as they are.)

**3d.** Add a private method `fetchImageBinary()` to the class (place it after `buildToFile()`, before `buildUniqueByBase()`):

```php
    /**
     * Fetch an image binary, preferring a web-sized Wikimedia thumbnail.
     *
     * When the thumbnail fetch fails, falls back to the original URL so an
     * image is never silently dropped. Returns null only when both fail.
     */
    private function fetchImageBinary(string $sourceUrl, string $userAgent, int $maxWidth): ?Response
    {
        $thumbnailUrl = $this->thumbnailUrls->forWidth($sourceUrl, $maxWidth);

        $response = Http::withHeaders(['User-Agent' => $userAgent])
            ->timeout(30)
            ->get($thumbnailUrl);

        if ($response->successful()) {
            return $response;
        }

        // The thumbnail URL was a transformed one and it failed — retry the
        // untouched original before giving up on this image.
        if ($thumbnailUrl !== $sourceUrl) {
            $original = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(30)
                ->get($sourceUrl);

            if ($original->successful()) {
                return $original;
            }
        }

        return null;
    }
```

The rest of the loop (filename generation via `buildUniqueByBase`, `$zip->addFromString`, `$added++`) is unchanged — it already uses `$response->body()`.

- [ ] **Step 4: Run the tests — verify they pass**

```bash
docker compose restart app
docker compose exec app php artisan test --filter=BatchZipBuilderTest
```

Expected: all 5 tests pass (3 existing + 2 new).

- [ ] **Step 5: Run the full test suite**

```bash
docker compose exec app php artisan test
```

Expected: all green, no regressions.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Downloads/BatchZipBuilder.php tests/Feature/Services/Downloads/BatchZipBuilderTest.php
git commit -m "$(cat <<'EOF'
feat(downloads): fetch web-sized thumbnails for the bulk ZIP

BatchZipBuilder now downloads a width-capped Wikimedia thumbnail
(config cars-images.download_max_width, default 1600px) instead of the
full-resolution original, shrinking the ZIP by ~85-90% with no
server-side image processing. Falls back to the original URL if the
thumbnail fetch fails.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: End-to-end verification

**Files:** none (verification only).

- [ ] **Step 1: Build a real ZIP and compare sizes**

Run this against the existing imported images (which have real Wikimedia `source_url`s):

```bash
docker compose exec app php artisan tinker --execute='
$imgs = \App\Models\CarImage::whereHas("search", fn($q) => $q->whereNotNull("csv_import_id"))->limit(4)->get();
if ($imgs->isEmpty()) { echo "no imported images to test with\n"; return; }
$tmp = tempnam(sys_get_temp_dir(), "verifyzip");
$added = app(\App\Services\Downloads\BatchZipBuilder::class)->buildToFile($imgs, $tmp);
echo "images: ".$imgs->count()." | entries: $added | zip size: ".(file_exists($tmp) ? round(filesize($tmp)/1048576, 2) : 0)." MB\n";
@unlink($tmp);
'
```

Expected: the ZIP builds successfully (`entries` equals the image count) and the size is materially smaller than the ~11 MB all-originals baseline — roughly 1–2 MB for 4 images at 1600px.

- [ ] **Step 2: No commit**

This task is verification only. If it surfaced a bug, fix it under the systematic-debugging skill and commit separately.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| `cars-images.download_max_width` config (default 1600, env-overridable, documented comment) | Task 1 |
| `WikimediaThumbnailUrlBuilder` with `forWidth()` + safe fallbacks | Task 2 |
| `BatchZipBuilder` fetches thumbnail URL, falls back to original on failure | Task 3 |
| Scope limited to bulk ZIP (single-image download + CSV untouched) | Tasks 3/4 touch only `BatchZipBuilder`; nothing else |
| Unit tests for the URL builder; feature tests for the integration | Tasks 2, 3 |
| ZIP materially smaller (acceptance criterion 4) | Task 4 |

All spec sections map to a task. No gaps.

**Placeholder scan:** No TBD/TODO/"add error handling" placeholders. Every code block is complete.

**Type/identifier consistency:**
- `WikimediaThumbnailUrlBuilder::forWidth(string, int): string` — defined Task 2, called Task 3 with `(string, int)`. Consistent.
- `BatchZipBuilder` constructor property `$thumbnailUrls` — declared and used consistently in Task 3.
- `fetchImageBinary(string, string, int): ?Response` — defined and called consistently within Task 3.
- Config key `cars-images.download_max_width` — identical across Tasks 1, 3, 4.
- Env key `CARS_DOWNLOAD_MAX_WIDTH` — identical across Task 1 and the config file.

No inconsistencies found.
