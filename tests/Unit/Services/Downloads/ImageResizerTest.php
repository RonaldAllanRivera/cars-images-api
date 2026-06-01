<?php

namespace Tests\Unit\Services\Downloads;

use App\Services\Downloads\ImageResizer;
use PHPUnit\Framework\TestCase;

class ImageResizerTest extends TestCase
{
    private ImageResizer $resizer;

    protected function setUp(): void
    {
        $this->resizer = new ImageResizer();
    }

    private function jpegBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function dimensions(string $bytes): array
    {
        $img = imagecreatefromstring($bytes);
        $dims = [imagesx($img), imagesy($img)];
        imagedestroy($img);

        return $dims;
    }

    public function test_resizes_a_large_image_down_to_max_width_keeping_aspect_ratio(): void
    {
        $original = $this->jpegBytes(3000, 2000);

        [$bytes, $ext] = $this->resizer->resize($original, 1600, 'jpg');

        [$w, $h] = $this->dimensions($bytes);

        $this->assertSame(1600, $w);
        $this->assertSame(1067, $h); // 2000 * (1600/3000) rounded
        $this->assertSame('jpg', $ext);
        $this->assertLessThan(strlen($original), strlen($bytes), 'resized image should be smaller');
    }

    public function test_does_not_upscale_images_already_within_max_width(): void
    {
        $original = $this->jpegBytes(800, 600);

        [$bytes, $ext] = $this->resizer->resize($original, 1600, 'jpg');

        [$w, $h] = $this->dimensions($bytes);

        $this->assertSame(800, $w);
        $this->assertSame(600, $h);
        $this->assertSame('jpg', $ext);
    }

    public function test_uses_the_configured_max_width(): void
    {
        $original = $this->jpegBytes(2000, 1000);

        [$bytes] = $this->resizer->resize($original, 1280, 'jpg');

        [$w] = $this->dimensions($bytes);

        $this->assertSame(1280, $w);
    }

    public function test_respects_custom_jpeg_quality(): void
    {
        $original = $this->jpegBytes(2000, 1500);

        [$low] = $this->resizer->resize($original, 1600, 'jpg', 30);
        [$high] = $this->resizer->resize($original, 1600, 'jpg', 95);

        // Higher quality should produce a larger file at the same dimensions.
        $this->assertGreaterThan(strlen($low), strlen($high));
    }

    public function test_returns_original_bytes_and_fallback_extension_for_non_image_input(): void
    {
        [$bytes, $ext] = $this->resizer->resize('this is not an image', 1600, 'png');

        $this->assertSame('this is not an image', $bytes);
        $this->assertSame('png', $ext);
    }
}
