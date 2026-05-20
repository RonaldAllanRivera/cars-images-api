<?php

namespace Tests\Unit\Services\Downloads;

use App\Services\Downloads\FilenameBuilder;
use PHPUnit\Framework\TestCase;

class FilenameBuilderTest extends TestCase
{
    private FilenameBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FilenameBuilder();
    }

    public function test_builds_basic_filename(): void
    {
        $name = $this->builder->build(1997, 'Toyota', 'RAV4', 'jpg');

        $this->assertSame('1997 Toyota RAV4.jpg', $name);
    }

    public function test_replaces_slash_with_dash(): void
    {
        $name = $this->builder->build(1998, 'Acura', '2.2CL/3.0CL', 'jpg');

        $this->assertSame('1998 Acura 2.2CL - 3.0CL.jpg', $name);
    }

    public function test_replaces_all_unsafe_chars(): void
    {
        $name = $this->builder->build(2024, 'Make', 'A:B*C?D"E<F>G|H\\I', 'png');

        $this->assertStringNotContainsString(':', $name);
        $this->assertStringNotContainsString('*', $name);
        $this->assertStringNotContainsString('?', $name);
        $this->assertStringNotContainsString('"', $name);
        $this->assertStringNotContainsString('<', $name);
        $this->assertStringNotContainsString('>', $name);
        $this->assertStringNotContainsString('|', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringEndsWith('.png', $name);
    }

    public function test_collapses_multiple_spaces(): void
    {
        $name = $this->builder->build(2020, 'Make  Name', 'Model   X', 'jpg');

        $this->assertSame('2020 Make Name Model X.jpg', $name);
    }

    public function test_caps_length_at_200_chars_plus_extension(): void
    {
        $longModel = str_repeat('A', 500);
        $name = $this->builder->build(2024, 'Toyota', $longModel, 'jpg');

        // Base (before extension) must be ≤ 200 chars
        $base = preg_replace('/\.jpg$/', '', $name);
        $this->assertLessThanOrEqual(200, mb_strlen($base));
    }

    public function test_dedup_returns_base_for_first_occurrence(): void
    {
        $used = [];
        $name = $this->builder->buildUnique(1997, 'Toyota', 'RAV4', 'jpg', $used);

        $this->assertSame('1997 Toyota RAV4.jpg', $name);
        $this->assertArrayHasKey('1997 Toyota RAV4.jpg', $used);
    }

    public function test_dedup_appends_counter_on_collision(): void
    {
        $used = ['1997 Toyota RAV4.jpg' => true];
        $name = $this->builder->buildUnique(1997, 'Toyota', 'RAV4', 'jpg', $used);

        $this->assertSame('1997 Toyota RAV4 2.jpg', $name);
    }

    public function test_dedup_continues_counting(): void
    {
        $used = [
            '1997 Toyota RAV4.jpg' => true,
            '1997 Toyota RAV4 2.jpg' => true,
            '1997 Toyota RAV4 3.jpg' => true,
        ];
        $name = $this->builder->buildUnique(1997, 'Toyota', 'RAV4', 'jpg', $used);

        $this->assertSame('1997 Toyota RAV4 4.jpg', $name);
    }

    public function test_extension_defaults_to_jpg_when_empty(): void
    {
        $name = $this->builder->build(2015, 'Mitsubishi', 'Mirage', '');

        $this->assertSame('2015 Mitsubishi Mirage.jpg', $name);
    }

    public function test_trim_leading_trailing_whitespace(): void
    {
        $name = $this->builder->build(2020, '  Toyota  ', '  RAV4  ', 'jpg');

        $this->assertSame('2020 Toyota RAV4.jpg', $name);
    }

    public function test_build_ranked_returns_base_for_rank_one(): void
    {
        $this->assertSame('1997 Toyota RAV4.jpg', $this->builder->buildRanked(1997, 'Toyota', 'RAV4', 'jpg', 1));
    }

    public function test_build_ranked_appends_suffix_for_higher_ranks(): void
    {
        $this->assertSame('1997 Toyota RAV4 2.jpg', $this->builder->buildRanked(1997, 'Toyota', 'RAV4', 'jpg', 2));
        $this->assertSame('1997 Toyota RAV4 5.jpg', $this->builder->buildRanked(1997, 'Toyota', 'RAV4', 'jpg', 5));
    }
}
