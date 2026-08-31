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
}
