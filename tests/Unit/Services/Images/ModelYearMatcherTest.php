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

    public function test_a_leading_photo_date_is_not_the_model_year(): void
    {
        // Mutation testing showed the date strip was never exercised: every
        // title pinning it also carried a leading model year, so the leading
        // branch answered correctly on its own. These titles put the capture
        // date FIRST, where only the strip can save them.
        $this->assertSame(1997, $this->matcher->modelYear('File:2017.1.23 1997 Acura CL.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:01-28-2010 Acura CL.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:8.2.20 Cadillac STS.jpg', 'Cadillac'));
    }

    public function test_an_upload_date_separated_by_spaces_is_not_the_model_year(): void
    {
        // MediaWiki renders filename underscores as spaces, so the very common
        // 2024_08_24_IMG_5653.JPG upload arrives as "2024 08 24 IMG 5653".
        // Commons files that one under "Honda Civic (2011, North America)".
        $this->assertNull($this->matcher->modelYear('File:2024 08 24 IMG 5653.JPG', 'Honda'));
        $this->assertNull($this->matcher->modelYear('File:2006 02 13 - College Park - Snowed In.jpg', 'Honda'));
    }

    public function test_an_event_year_with_no_make_named_is_not_a_model_year(): void
    {
        // The most damaging real failure: race, auto-show and news years lead
        // the title and were stored as the model year with year_confirmed=true.
        // "2016 Sebring" is filed by Commons as a 2011 Civic; "2010 ASA AutoX"
        // as a 1987 Civic — 23 years out.
        $this->assertNull($this->matcher->modelYear('File:2016 Sebring DSC 8285 (28278812954).jpg', 'Honda'));
        $this->assertNull($this->matcher->modelYear('File:2010 ASA AutoX 4744 (5004598645).jpg', 'Honda'));
        $this->assertNull($this->matcher->modelYear('File:2019 Canadian International Auto Show (32198734577).jpg', 'Honda'));
        $this->assertNull($this->matcher->modelYear('File:2012 North American International Auto Show (6729665331).jpg', 'Toyota'));
    }

    public function test_the_year_beside_the_make_beats_a_leading_event_year(): void
    {
        // Both branches fire and disagree. The make-adjacent year is the
        // stronger assertion: the title states the model year explicitly.
        $this->assertSame(2016, $this->matcher->modelYear('File:2015 Detroit Auto Show 2016 Ford Mustang.jpg', 'Ford'));
        $this->assertSame(1997, $this->matcher->modelYear('File:2010 photo of a 1997 Acura CL.jpg', 'Acura'));
    }

    public function test_a_make_written_without_its_hyphen_still_matches(): void
    {
        // Commons writes "Mercedes Benz" as readily as "Mercedes-Benz".
        $this->assertSame(1963, $this->matcher->modelYear('File:1963 Mercedes Benz 220 SEb Coupe.jpg', 'Mercedes-Benz'));
        $this->assertSame(1993, $this->matcher->modelYear('File:1993 Mercedes 300 SE Auto.jpg', 'Mercedes'));
        $this->assertSame(2019, $this->matcher->modelYear('File:2019 Land Rover Range Rover.jpg', 'Land Rover'));
    }
}
