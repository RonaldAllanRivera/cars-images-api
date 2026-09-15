<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The image set an export contains.
 *
 * Shared by the endpoint that mints a link and the route that serves it, so the
 * count a user was shown and the file they receive come from one query. They
 * are computed at different moments on purpose - images can arrive in the
 * minutes between - but never by different code.
 *
 * Filters arrive already vouched for: by ListImagesRequest's rules at mint
 * time, and by the URL signature at download time. That is why this takes a
 * plain array rather than a FormRequest - the download route has no request to
 * validate, and re-validating what the signature already guarantees would only
 * add a way for the two to disagree.
 */
class ExportQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters): int
    {
        return $this->query($filters)->count();
    }

    /**
     * With `search` loaded: BatchCsvExporter reads it for the transmission
     * column, which would otherwise be one query per row.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CarImage>
     */
    public function images(array $filters): Collection
    {
        return $this->query($filters)->with('search')->orderBy('id')->get();
    }

    /** The ZIP is built synchronously in one request, so it is capped. */
    public function zipCap(): int
    {
        return (int) config('cars-images.bulk_download_max_images', 100);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CarImage>
     */
    private function query(array $filters): Builder
    {
        $query = CarImage::query();

        foreach (['make', 'model', 'year', 'review_status', 'download_status'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $filters[$column]);
            }
        }

        // A verdict filter matches its value only. The nullable "unknown" is
        // neither true nor false and never matches - the rule the grid applies,
        // so exporting "matched" cannot sweep in images the checker never
        // reached.
        foreach (['make_confirmed', 'year_confirmed'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, filter_var($filters[$column], FILTER_VALIDATE_BOOLEAN));
            }
        }

        // Through the search: an image belongs to a search, the search to an
        // import.
        if (array_key_exists('csv_import_id', $filters)) {
            $query->whereHas('search', fn (Builder $q) => $q->where('csv_import_id', $filters['csv_import_id']));
        }

        return $query;
    }
}
