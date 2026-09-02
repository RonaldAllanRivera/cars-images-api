<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\CommonsCategoryResolver;
use PHPUnit\Framework\TestCase;

/**
 * Commons category names carry a make and a model and nothing else.
 * The CSV carries EPA trim, drivetrain and body qualifiers on top:
 * "Santa Fe XL AWD", "F150 Pickup 2WD FFV", "Cooper Hardtop 2 door".
 * Those extra tokens are why the old normalizer resolved 5 of 30 models.
 */
class CommonsCategoryResolverTest extends TestCase
{
    private CommonsCategoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CommonsCategoryResolver;
    }

    public function test_candidates_run_most_specific_first(): void
    {
        $candidates = $this->resolver->candidates('Hyundai', 'Santa Fe XL AWD');

        $this->assertSame('Hyundai Santa Fe XL AWD', $candidates[0]);
        $this->assertContains('Hyundai Santa Fe XL', $candidates);
        $this->assertContains('Hyundai Santa Fe', $candidates);
        $this->assertLessThan(
            array_search('Hyundai Santa Fe', $candidates, true),
            array_search('Hyundai Santa Fe XL', $candidates, true),
            'A longer, more specific category must be probed before a shorter one.'
        );
    }

    public function test_the_engine_displacement_prefix_is_normalized_away(): void
    {
        // Category:Acura 2.3CL/3.0CL does not exist; Category:Acura CL does.
        $this->assertSame(['Acura CL'], $this->resolver->candidates('Acura', '2.3CL/3.0CL'));
    }

    public function test_drivetrain_and_body_qualifiers_are_stripped(): void
    {
        $this->assertContains('Ford F150', $this->resolver->candidates('Ford', 'F150 Pickup 2WD FFV'));
        $this->assertContains('BMW 328i', $this->resolver->candidates('BMW', '328i xDrive'));
        $this->assertContains('Cadillac STS', $this->resolver->candidates('Cadillac', 'STS AWD'));
    }

    public function test_parentheticals_are_dropped(): void
    {
        $this->assertContains('BMW i4', $this->resolver->candidates('BMW', 'i4 eDrive35 Gran Coupe (18 inch Wheels)'));
    }

    public function test_a_bare_make_is_never_a_candidate(): void
    {
        // Category:Mitsubishi is the whole brand. Probing it would attach
        // arbitrary Mitsubishis to a search for a specific truck.
        $this->assertNotContains('Mitsubishi', $this->resolver->candidates('Mitsubishi', 'Truck 2WD'));
        $this->assertNotContains('MINI', $this->resolver->candidates('MINI', 'Cooper Hardtop 2 door'));
    }

    public function test_a_single_token_model_yields_one_candidate(): void
    {
        $this->assertSame(['Saturn L200'], $this->resolver->candidates('Saturn', 'L200'));
    }

    public function test_a_model_beginning_with_a_qualifier_still_reaches_its_own_category(): void
    {
        // "New" and "Convertible" are both qualifiers, so stripping reduced
        // "New Beetle Convertible" to the single token "Beetle" and the shrink
        // loop never ran. The only names offered were the full string and
        // "Volkswagen Beetle" — the 1938 Type 1 — so a New Beetle search stored
        // classic Beetles, cached forever because hits never expire.
        $candidates = $this->resolver->candidates('Volkswagen', 'New Beetle Convertible');

        $this->assertContains('Volkswagen New Beetle', $candidates);
        $this->assertLessThan(
            array_search('Volkswagen Beetle', $candidates, true),
            array_search('Volkswagen New Beetle', $candidates, true),
            'The specific category must be probed before the broader one.'
        );
    }

    public function test_a_qualifier_that_is_part_of_a_real_model_name_is_still_reachable(): void
    {
        // "New" is a qualifier, but Chrysler really does sell a New Yorker.
        $candidates = $this->resolver->candidates('Chrysler', 'New Yorker Turbo');

        $this->assertContains('Chrysler New Yorker', $candidates);
        $this->assertLessThan(
            array_search('Chrysler Yorker', $candidates, true),
            array_search('Chrysler New Yorker', $candidates, true),
        );
    }

    public function test_candidates_are_ordered_from_most_to_least_specific(): void
    {
        // The caller takes the first candidate that exists, so ordering is the
        // whole contract: a broader name reached earlier attaches the wrong
        // photographs to the search.
        foreach ([
            ['Volkswagen', 'New Beetle Convertible'],
            ['Hyundai', 'Santa Fe XL AWD'],
            ['Chrysler', 'New Yorker Turbo'],
        ] as [$make, $model]) {
            $counts = array_map(
                static fn (string $c): int => substr_count($c, ' '),
                $this->resolver->candidates($make, $model),
            );

            $sorted = $counts;
            rsort($sorted);

            $this->assertSame(
                $sorted,
                $counts,
                "Candidates for {$make} {$model} are not in descending specificity order."
            );
        }
    }

    public function test_candidates_containing_mediawiki_illegal_characters_are_dropped(): void
    {
        // 27 distinct models in the EPA CSV carry a ">" in a GVWR clause.
        // MediaWiki rejects "<", ">", "#", "[", "]", "|", "{" and "}" in a
        // page title outright, and answers a query for one with
        // {"invalid": true} rather than {"missing": true} — which reads as
        // "this category exists". Such a candidate must never be probed.
        $candidates = $this->resolver->candidates('Ford', 'F150 2.7L 2WD GVWR>6649 LBS');

        foreach ($candidates as $candidate) {
            $this->assertDoesNotMatchRegularExpression(
                '/[#<>\[\]|{}]/',
                $candidate,
                "Candidate \"{$candidate}\" carries a character MediaWiki cannot put in a title."
            );
        }

        $this->assertContains(
            'Ford F150',
            $candidates,
            'Dropping the illegal candidates must still leave the shortened one that actually exists.'
        );
    }
}
