<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\MakeRelevanceChecker;
use PHPUnit\Framework\TestCase;

class MakeRelevanceCheckerTest extends TestCase
{
    private MakeRelevanceChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new MakeRelevanceChecker();
    }

    public function test_confirms_when_make_appears_in_title(): void
    {
        $this->assertTrue(
            $this->checker->isConfirmed('Toyota', 'File:Toyota Camry 2020.jpg', null, null)
        );
    }

    public function test_confirms_case_insensitively(): void
    {
        $this->assertTrue(
            $this->checker->isConfirmed('ACURA', 'File:acura nsx 1999.jpg', null, null)
        );
    }

    public function test_confirms_when_make_appears_in_description(): void
    {
        $this->assertTrue(
            $this->checker->isConfirmed('Honda', 'File:car.jpg', 'A red Honda Civic on a road', null)
        );
    }

    public function test_confirms_when_make_appears_in_categories(): void
    {
        $this->assertTrue(
            $this->checker->isConfirmed('Mazda', 'File:car.jpg', null, 'Mazda MX-5|Cars in Japan')
        );
    }

    public function test_strips_html_from_description_before_matching(): void
    {
        $this->assertTrue(
            $this->checker->isConfirmed('Honda', 'File:car.jpg', '<p>A <b>Honda</b> Accord</p>', null)
        );
    }

    public function test_does_not_confirm_when_make_is_absent(): void
    {
        // The classic case: searching "Acura" but the image is a Honda Accord.
        $this->assertFalse(
            $this->checker->isConfirmed('Acura', 'File:Honda Accord CL3 europe.jpg', 'Honda Accord coupe', 'Honda Accord')
        );
    }

    public function test_does_not_confirm_with_empty_make(): void
    {
        $this->assertFalse($this->checker->isConfirmed('', 'File:Toyota.jpg', null, null));
        $this->assertFalse($this->checker->isConfirmed(null, 'File:Toyota.jpg', null, null));
    }

    public function test_does_not_confirm_with_no_haystack(): void
    {
        $this->assertFalse($this->checker->isConfirmed('Toyota', null, null, null));
    }
}
