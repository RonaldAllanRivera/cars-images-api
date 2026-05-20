<?php

namespace App\Services\Images;

class ModelSearchTermNormalizer
{
    /**
     * Normalize a raw CSV model string into a Wikimedia-friendly search term.
     *
     * Vehicle CSVs often encode the model as an engine-displacement + model
     * letters string, e.g. "2.2CL/3.0CL" or "2.5TL". Wikimedia Commons titles
     * and categories use the bare model name ("CL", "TL"), so the displacement
     * prefix and the slash-separated trim variants must be collapsed:
     *
     *   "2.2CL/3.0CL" -> "CL"
     *   "2.5TL"       -> "TL"
     *   "Camry"       -> "Camry"   (unchanged — no displacement prefix)
     *   "626"         -> "626"     (unchanged — pure-digit model, no dot)
     *   "A4"          -> "A4"      (unchanged)
     *   "5.0"         -> "5.0"     (unchanged — stripping would leave nothing)
     *
     * The transformation is conservative: it only strips a leading
     * "<digits>.<digits>" pattern, and keeps the original segment whenever
     * stripping would leave fewer than 2 characters.
     */
    public function normalize(string $model): string
    {
        $normalized = [];

        foreach (explode('/', $model) as $segment) {
            $original = trim($segment);
            if ($original === '') {
                continue;
            }

            $stripped = trim(preg_replace('/^\d+\.\d+\s*/', '', $original));

            $candidate = mb_strlen($stripped) >= 2 ? $stripped : $original;

            if (! in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return implode(' ', $normalized);
    }
}
