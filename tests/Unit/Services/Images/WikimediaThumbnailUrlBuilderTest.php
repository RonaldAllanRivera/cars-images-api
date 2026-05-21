<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\WikimediaThumbnailUrlBuilder;
use PHPUnit\Framework\TestCase;

class WikimediaThumbnailUrlBuilderTest extends TestCase
{
    private WikimediaThumbnailUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new WikimediaThumbnailUrlBuilder();
    }

    public function test_builds_thumbnail_url_from_standard_commons_url(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg';

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1600px-Foo.jpg',
            $this->builder->forWidth($source, 1600),
        );
    }

    public function test_uses_the_requested_width(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Bar.png';

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Bar.png/1280px-Bar.png',
            $this->builder->forWidth($source, 1280),
        );
    }

    public function test_preserves_url_encoded_filename(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/4/47/2014_Show_%281999_Acura_NSX%29.jpg';

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/2014_Show_%281999_Acura_NSX%29.jpg/1600px-2014_Show_%281999_Acura_NSX%29.jpg',
            $this->builder->forWidth($source, 1600),
        );
    }

    public function test_returns_non_wikimedia_url_unchanged(): void
    {
        $source = 'https://example.com/images/car.jpg';

        $this->assertSame($source, $this->builder->forWidth($source, 1600));
    }

    public function test_returns_already_thumbnail_url_unchanged(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1280px-Foo.jpg';

        $this->assertSame($source, $this->builder->forWidth($source, 1600));
    }

    public function test_returns_svg_source_unchanged(): void
    {
        $source = 'https://upload.wikimedia.org/wikipedia/commons/4/47/Logo.svg';

        $this->assertSame($source, $this->builder->forWidth($source, 1600));
    }
}
