<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\ModelYearMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Seeded with the real titles of Category:Acura CL YA1, which is where
 * every trap in this problem actually lives.
 */
class ModelYearMatcherTest extends TestCase
{
    private ModelYearMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ModelYearMatcher;
    }

    public function test_a_leading_year_is_the_model_year(): void
    {
        $this->assertSame(1999, $this->matcher->modelYear('File:1999 Acura CL 3.0.jpg', 'Acura'));
        $this->assertSame(1999, $this->matcher->modelYear('File:1999 Acura CL.jpg', 'Acura'));
        $this->assertSame(1996, $this->matcher->modelYear('File:1996 Acura 3.0 CL 2017.1.23.jpg', 'Acura'));
    }

    public function test_a_trailing_photo_date_is_not_the_model_year(): void
    {
        // The single most damaging failure mode: 01-28-2010 is when the
        // photograph was taken. Reading it as a model year files a 1997
        // Acura CL under 2010.
        $this->assertSame(1997, $this->matcher->modelYear('File:1997 Acura CL -- 01-28-2010.jpg', 'Acura'));
        $this->assertSame(1997, $this->matcher->modelYear('File:1997 Acura CL, rear 8.2.20.jpg', 'Acura'));
    }

    public function test_a_year_range_is_not_an_exact_year(): void
    {
        $this->assertNull($this->matcher->modelYear('File:1997-1999 Acura 3.0CL — 04-25-2026.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:1998-1999 Acura CL -- 04-11-2012 1.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:1998-99 Acura CL.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear("File:'98-'99 Acura CL.jpg", 'Acura'));
    }

    public function test_a_title_with_no_year_yields_null(): void
    {
        $this->assertNull($this->matcher->modelYear('File:1st gen Acura CL.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:First Acura CL.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:1st-Acura-CL-1.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:Clx.jpg', 'Acura'));
    }

    public function test_a_year_immediately_before_the_make_counts(): void
    {
        $this->assertSame(2005, $this->matcher->modelYear('File:Blue 2005 Cadillac STS.jpg', 'Cadillac'));
    }

    public function test_implausible_years_are_rejected(): void
    {
        $this->assertNull($this->matcher->modelYear('File:1023 Acura CL.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:3500 Acura CL.jpg', 'Acura'));
    }
}
