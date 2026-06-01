<?php

namespace App\Services\Downloads;

use GdImage;

class ImageResizer
{
    /**
     * Resize raw image bytes down to a maximum width and re-encode as JPEG.
     *
     * Wikimedia blocks on-demand thumbnail generation from datacenter /
     * shared-hosting IPs (every /thumb/ URL returns HTTP 400 from the
     * production server), so the bulk-download ZIP cannot rely on Wikimedia's
     * thumbnail CDN. Instead we download the full-resolution original and
     * shrink it here with GD.
     *
     * Images already within $maxWidth are not upscaled — they are only
     * re-encoded as JPEG for the compression saving. On any failure
     * (unsupported format, GD error) the original bytes are returned with
     * $fallbackExtension, so an image is never lost from the archive.
     *
     * @return array{0: string, 1: string} [bytes, extension]
     */
    public function resize(string $bytes, int $maxWidth, string $fallbackExtension = 'jpg', int $quality = 82): array
    {
        $image = $this->decode($bytes);
        if (! $image instanceof GdImage) {
            return [$bytes, $fallbackExtension];
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);

            return [$bytes, $fallbackExtension];
        }

        if ($width <= $maxWidth) {
            // Within bounds — recompress only, no upscaling.
            $out = $this->encodeJpeg($image, $quality);
            imagedestroy($image);

            return $out !== null ? [$out, 'jpg'] : [$bytes, $fallbackExtension];
        }

        $newWidth = $maxWidth;
        $newHeight = (int) round($height * ($maxWidth / $width));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Flatten any transparency onto white, since JPEG has no alpha.
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $white);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $out = $this->encodeJpeg($resized, $quality);

        imagedestroy($image);
        imagedestroy($resized);

        return $out !== null ? [$out, 'jpg'] : [$bytes, $fallbackExtension];
    }

    /**
     * Decode image bytes into a GdImage, silencing the warning GD emits for
     * unrecognised data (so callers get a clean false instead of a warning).
     */
    private function decode(string $bytes): GdImage|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
    }

    private function encodeJpeg(GdImage $image, int $quality): ?string
    {
        ob_start();
        $ok = imagejpeg($image, null, $quality);
        $data = ob_get_clean();

        return ($ok && is_string($data) && $data !== '') ? $data : null;
    }
}
