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
    public function locate(string $make, string $model): ?string
    {
        $lookup = CommonsCategoryLookup::query()
            ->where('make', $make)
            ->where('model', $model)
            ->first();

        if ($lookup !== null && ! $this->isStaleMiss($lookup)) {
            return $lookup->category;
        }

        $category = null;

        foreach ($this->resolver->candidates($make, $model) as $candidate) {
            if ($this->wikimedia->categoryExists($candidate)) {
                $category = $candidate;
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
