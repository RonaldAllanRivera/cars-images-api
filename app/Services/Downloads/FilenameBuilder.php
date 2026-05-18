<?php

namespace App\Services\Downloads;

class FilenameBuilder
{
    private const UNSAFE_CHARS = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];
    private const MAX_BASE_LENGTH = 200;
    private const DEFAULT_EXTENSION = 'jpg';

    /**
     * Generate a deterministic, filesystem-safe base filename.
     *
     * Output: "YEAR MAKE MODEL.ext", e.g. "1997 Toyota RAV4.jpg".
     */
    public function build(int $year, string $make, string $model, string $extension): string
    {
        $base = sprintf('%d %s %s', $year, $make, $model);
        $base = str_replace(self::UNSAFE_CHARS, ' - ', $base);
        $base = preg_replace('/\s+/', ' ', $base);
        $base = trim($base);
        $base = mb_substr($base, 0, self::MAX_BASE_LENGTH);

        $extension = $extension === '' ? self::DEFAULT_EXTENSION : strtolower($extension);
        $extension = preg_replace('/[^a-z0-9]/', '', $extension);
        if ($extension === '') {
            $extension = self::DEFAULT_EXTENSION;
        }

        return $base . '.' . $extension;
    }

    /**
     * Generate a filename guaranteed unique within $usedNames.
     * Mutates $usedNames to track issued names.
     *
     * Collision pattern: "BASE.ext", then "BASE 2.ext", "BASE 3.ext", ...
     */
    public function buildUnique(
        int $year,
        string $make,
        string $model,
        string $extension,
        array &$usedNames
    ): string {
        $candidate = $this->build($year, $make, $model, $extension);

        if (! isset($usedNames[$candidate])) {
            $usedNames[$candidate] = true;
            return $candidate;
        }

        // Split base from extension to insert counter before extension
        $extPos = strrpos($candidate, '.');
        $base = substr($candidate, 0, $extPos);
        $ext = substr($candidate, $extPos);

        $counter = 2;
        do {
            $candidate = "{$base} {$counter}{$ext}";
            $counter++;
        } while (isset($usedNames[$candidate]));

        $usedNames[$candidate] = true;

        return $candidate;
    }
}
