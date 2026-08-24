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

    /**
     * Decide whether an image plainly belongs to a *different* manufacturer.
     *
     * Wikimedia full-text search matches loosely: querying "Acura CL 1997"
     * returns "Honda Accord CL3" photographs, because the Accord's chassis
     * code "CL3" contains the model token "CL". Merely flagging those is not
     * enough — a search for an Acura must not show a Honda.
     *
     * The rule is deliberately asymmetric:
     *
     *   - searched make present anywhere  -> keep (covers badge engineering,
     *     e.g. a page titled "Honda Accord (Acura CL)")
     *   - a DIFFERENT known make present  -> reject, it is another car
     *   - no make named at all            -> keep, absence of evidence is not
     *     evidence of a wrong car; `isConfirmed()` still flags it for review
     *
     * Matching is on whole words, so "Fordson" is not a Ford and "Acura" is
     * not matched inside a longer token.
     *
     * @param  array<int, string>  $knownMakes  the catalogue of makes to test against
     */
    public function isOffMake(
        ?string $searchedMake,
        ?string $title,
        ?string $description,
        ?string $categories,
        array $knownMakes,
    ): bool {
        $searched = mb_strtolower(trim((string) $searchedMake));

        if ($searched === '') {
            return false;
        }

        $haystack = mb_strtolower(trim(implode(' ', [
            (string) $title,
            strip_tags((string) $description),
            (string) $categories,
        ])));

        if ($haystack === '') {
            return false;
        }

        // The searched make is named, so this is the right car (or a
        // badge-engineered listing of it). Keep it.
        if ($this->mentions($haystack, $searched)) {
            return false;
        }

        foreach ($knownMakes as $make) {
            $candidate = mb_strtolower(trim((string) $make));

            if ($candidate === '' || $candidate === $searched) {
                continue;
            }

            if ($this->mentions($haystack, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole-word, case-insensitive containment.
     *
     * Guards against a make name matching inside a longer token — "Fordson"
     * must not register as "Ford".
     */
    private function mentions(string $haystack, string $needle): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $haystack) === 1;
    }
}
