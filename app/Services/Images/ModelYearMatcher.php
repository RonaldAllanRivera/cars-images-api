<?php

namespace App\Services\Images;

class ModelYearMatcher
{
    /**
     * Dates a photograph was taken, in the forms Commons filenames use:
     * "01-28-2010", "8.2.20" and "2017.1.23". Stripped before anything else
     * looks for a year — otherwise the photo date wins over the model year
     * and a 1997 Acura CL is filed under 2010.
     */
    private const PHOTO_DATE = '/(?<!\d)(?:\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4}|\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2})(?!\d)/';

    /**
     * "1997-1999", "1998-99", "'98-'99". A range names no single model year,
     * so under the exact-year policy the whole title is disqualified.
     */
    private const YEAR_RANGE = "/(?<!\d)(?:\d{4}|'\d{2})\s*[-\x{2013}\x{2014}]\s*'?\d{2,4}(?!\d)/u";

    private const EARLIEST = 1885;

    /**
     * The model year this Commons file title asserts, or null.
     *
     * Commons titles put the model year first — "1999 Acura CL 3.0.jpg" —
     * and the capture date, when present, last. That ordering is the only
     * reliable signal available, so the match is anchored to it rather than
     * to "any four digits anywhere".
     */
    public function modelYear(string $title, string $make): ?int
    {
        $text = preg_replace('/^File:/i', '', $title);
        $text = preg_replace(self::PHOTO_DATE, ' ', $text);

        if (preg_match(self::YEAR_RANGE, $text) === 1) {
            return null;
        }

        if (preg_match('/^\s*(\d{4})\b/', $text, $matches) === 1) {
            return $this->plausible((int) $matches[1]);
        }

        if (preg_match('/(?<!\d)(\d{4})\s+'.preg_quote($make, '/').'/iu', $text, $matches) === 1) {
            return $this->plausible((int) $matches[1]);
        }

        return null;
    }

    /**
     * Four consecutive digits are not necessarily a year — "1023" and "3500"
     * are model designations. Bound to the era of the motor car.
     */
    private function plausible(int $year): ?int
    {
        return $year >= self::EARLIEST && $year <= (int) date('Y') + 2 ? $year : null;
    }
}
