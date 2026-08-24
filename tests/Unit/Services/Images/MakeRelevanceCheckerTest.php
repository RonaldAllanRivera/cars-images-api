<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\MakeRelevanceChecker;
use PHPUnit\Framework\TestCase;

class MakeRelevanceCheckerTest extends TestCase
{
    private MakeRelevanceChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new MakeRelevanceChecker;
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

    /*
    |--------------------------------------------------------------------------
    | Off-make rejection
    |--------------------------------------------------------------------------
    |
    | Wikimedia full-text search matches loosely. Searching "Acura CL 1997"
    | returns "Honda Accord CL3" photographs, because the chassis code "CL3"
    | contains the model token "CL". Flagging those is not enough — a search
    | for an Acura must not display a Honda.
    |
    | The rule: if the image names a DIFFERENT known manufacturer and does
    | not name the searched one, it belongs to another car. When the searched
    | make is present, or no other make is named at all, we keep the image
    | and leave the decision to the reviewer.
    |
    */

    private const KNOWN_MAKES = ['Acura', 'Honda', 'Toyota', 'Lexus', 'Nissan', 'Ford', 'BMW', 'Mercedes-Benz'];

    public function test_rejects_an_image_naming_a_different_make(): void
    {
        $checker = new MakeRelevanceChecker;

        $this->assertTrue($checker->isOffMake(
            'Acura',
            'File:Honda Accord CL3 europe.jpg',
            null,
            'Silver Honda sedans|Honda Accord (1997, Europe)',
            self::KNOWN_MAKES,
        ));
    }

    public function test_keeps_an_image_that_names_the_searched_make(): void
    {
        $checker = new MakeRelevanceChecker;

        $this->assertFalse($checker->isOffMake(
            'Acura',
            'File:Clx.jpg',
            null,
            'Acura CL-X|Self-published work',
            self::KNOWN_MAKES,
        ));
    }

    public function test_keeps_a_badge_engineered_image_naming_both_makes(): void
    {
        // The Acura CL is catalogued by Wikimedia as a Honda Accord. When the
        // page names both, it is genuinely the searched car and must survive.
        $checker = new MakeRelevanceChecker;

        $this->assertFalse($checker->isOffMake(
            'Acura',
            'File:Honda Accord (Acura CL) 1997.jpg',
            null,
            'Honda Accord|Acura CL',
            self::KNOWN_MAKES,
        ));
    }

    public function test_keeps_an_image_that_names_no_make_at_all(): void
    {
        // Conservative: absence of evidence is not evidence of a wrong car.
        $checker = new MakeRelevanceChecker;

        $this->assertFalse($checker->isOffMake(
            'Acura',
            'File:Silver coupe on a driveway.jpg',
            null,
            'Self-published work',
            self::KNOWN_MAKES,
        ));
    }

    public function test_match_is_case_insensitive_and_ignores_html(): void
    {
        $checker = new MakeRelevanceChecker;

        $this->assertTrue($checker->isOffMake(
            'Acura',
            'File:HONDA ACCORD (CG1,CG2) China.jpg',
            '<p>A <b>honda</b> sedan</p>',
            null,
            self::KNOWN_MAKES,
        ));
    }

    public function test_does_not_reject_on_a_substring_of_a_longer_word(): void
    {
        // "Fordson" is a tractor brand, not Ford. Word boundaries matter, or
        // the filter starts eating legitimate results.
        $checker = new MakeRelevanceChecker;

        $this->assertFalse($checker->isOffMake(
            'Acura',
            'File:Fordson tractor.jpg',
            null,
            null,
            ['Acura', 'Ford'],
        ));
    }

    public function test_keeps_everything_when_the_searched_make_is_empty(): void
    {
        $checker = new MakeRelevanceChecker;

        $this->assertFalse($checker->isOffMake('', 'File:Honda Accord.jpg', null, null, self::KNOWN_MAKES));
    }
}
