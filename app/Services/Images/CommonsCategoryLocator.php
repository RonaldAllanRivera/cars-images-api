<?php

namespace App\Services\Images;

use App\Models\CommonsCategoryLookup;

class CommonsCategoryLocator
{
    public function __construct(
        protected CommonsCategoryResolver $resolver,
        protected WikimediaClient $wikimedia,
    ) {}

    /**
     * The Commons category holding photographs of this model, or null.
     *
     * Resolution costs roughly one API call per candidate, so the answer is
     * cached in the database rather than only for the request: the source CSV
     * holds 5,136 distinct models, and every year of each one asks again.
     */
    public function locate(string $make, ?string $model): ?string
    {
        // An ad-hoc search may leave the model blank ("all models"). There is
        // no category for that: Category:Acura is the whole marque, and
        // attaching every Acura ever photographed to a search for one model
        // year is exactly what CommonsCategoryResolver refuses to do.
        if ($model === null || trim($model) === '') {
            return null;
        }

        $lookup = CommonsCategoryLookup::query()
            ->where('make', $make)
            ->where('model', $model)
            ->first();

        if ($lookup !== null && ! $this->isStaleMiss($lookup)) {
            return $lookup->category;
        }

        $category = null;

        foreach ($this->resolver->candidates($make, $model) as $candidate) {
            // The resolved name can differ from the candidate: Commons points
            // "Ford F150" at "Ford F-150", and no candidate built from the CSV
            // could spell the hyphenated form. Store what actually holds the
            // photographs, not what we guessed.
            $resolved = $this->wikimedia->resolveCategory($candidate);

            if ($resolved !== null) {
                $category = $resolved;
                break;
            }
        }

        CommonsCategoryLookup::updateOrCreate(
            ['make' => $make, 'model' => $model],
            ['category' => $category, 'checked_at' => now()],
        );

        return $category;
    }

    /**
     * A hit never expires — a category that exists does not stop existing.
     * A miss does, because Commons categories are created over time and a
     * model without one today may have one next year.
     */
    private function isStaleMiss(CommonsCategoryLookup $lookup): bool
    {
        if ($lookup->category !== null) {
            return false;
        }

        $days = (int) config('images.wikimedia.category_miss_ttl_days', 30);

        return $lookup->checked_at === null
            || $lookup->checked_at->lt(now()->subDays($days));
    }
}
