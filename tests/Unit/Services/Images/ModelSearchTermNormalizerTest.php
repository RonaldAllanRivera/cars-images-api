<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\ModelSearchTermNormalizer;
use PHPUnit\Framework\TestCase;

class ModelSearchTermNormalizerTest extends TestCase
{
    private ModelSearchTermNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ModelSearchTermNormalizer();
    }

    public function test_strips_displacement_prefix_and_collapses_slash_variants(): void
    {
        $this->assertSame('CL', $this->normalizer->normalize('2.2CL/3.0CL'));
        $this->assertSame('CL', $this->normalizer->normalize('2.3CL/3.0CL'));
    }

    public function test_strips_displacement_prefix_from_single_segment(): void
    {
        $this->assertSame('TL', $this->normalizer->normalize('2.5TL'));
        $this->assertSame('CL', $this->normalizer->normalize('3.2CL'));
    }

    public function test_leaves_clean_model_names_unchanged(): void
    {
        $this->assertSame('Camry', $this->normalizer->normalize('Camry'));
        $this->assertSame('RAV4', $this->normalizer->normalize('RAV4'));
        $this->assertSame('RSX Type-S', $this->normalizer->normalize('RSX Type-S'));
    }

    public function test_leaves_pure_digit_models_unchanged(): void
    {
        // No decimal point -> not a displacement prefix.
        $this->assertSame('626', $this->normalizer->normalize('626'));
        $this->assertSame('300', $this->normalizer->normalize('300'));
    }

    public function test_leaves_alphanumeric_models_unchanged(): void
    {
        $this->assertSame('A4', $this->normalizer->normalize('A4'));
        $this->assertSame('M3', $this->normalizer->normalize('M3'));
        $this->assertSame('G37', $this->normalizer->normalize('G37'));
    }

    public function test_keeps_original_when_stripping_leaves_too_little(): void
    {
        // "5.0" stripped would be empty -> keep original.
        $this->assertSame('5.0', $this->normalizer->normalize('5.0'));
        // "1.8T" stripped would be "T" (1 char) -> keep original.
        $this->assertSame('1.8T', $this->normalizer->normalize('1.8T'));
    }

    public function test_handles_empty_and_whitespace(): void
    {
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertSame('', $this->normalizer->normalize('   '));
    }

    public function test_deduplicates_identical_normalized_segments(): void
    {
        $this->assertSame('CL', $this->normalizer->normalize('2.2CL/2.3CL/3.0CL'));
    }
}
