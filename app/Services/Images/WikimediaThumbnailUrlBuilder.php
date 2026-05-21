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
