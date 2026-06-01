<?php

namespace App\Services\Images;

class MakeRelevanceChecker
{
    /**
     * Decide whether an image is actually relevant to the searched make.
     *
     * Wikimedia Commons is a global media library, so badge-engineered or
     * region-specific models surface under a different make (e.g. the Acura
     * CL is filed as a "Honda Accord"). This check confirms the searched make
     * actually appears in the image's title, description, or categories — so
     * obviously off-make results can be flagged in the review UI rather than
     * silently trusted.
     *
     * The match is a simple case-insensitive substring test across the three
     * text signals. It is deliberately conservative: it confirms presence,
     * it does not try to disambiguate models.
     */
    public function isConfirmed(?string $make, ?string $title, ?string $description, ?string $categories): bool
    {
        $make = strtolower(trim((string) $make));
        if ($make === '') {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $title,
            strip_tags((string) $description),
            (string) $categories,
        ])));

        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, $make);
    }
}
