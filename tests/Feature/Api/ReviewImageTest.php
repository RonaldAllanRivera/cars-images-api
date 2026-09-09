<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarImage;

class ReviewImageTest extends ApiTestCase
{
    public function test_reviewing_requires_the_review_write_ability(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $image = $this->image($this->search($user));

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'approved'])
            ->assertForbidden();
    }

    public function test_approving_stamps_the_reviewer_and_leaves_the_machine_verdict_alone(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user), ['make_confirmed' => false, 'year_confirmed' => false]);

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.reviewed_by', $user->id)
            ->assertJsonPath('data.make_confirmed', false);

        $image->refresh();
        $this->assertSame(CarImage::REVIEW_APPROVED, $image->review_status);
        $this->assertSame($user->id, $image->reviewed_by);
        $this->assertNotNull($image->reviewed_at);
        $this->assertFalse($image->make_confirmed, 'the human verdict must not overwrite the machine one');
        $this->assertFalse($image->year_confirmed);
    }

    public function test_rejecting_works_the_same_way(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user));

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'rejected')
            ->assertJsonPath('data.reviewed_by', $user->id);
    }

    public function test_returning_to_pending_clears_the_reviewer(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user), [
            'review_status' => CarImage::REVIEW_APPROVED, 'reviewed_by' => $user->id, 'reviewed_at' => now(),
        ]);

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonPath('data.reviewed_at', null);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $image = $this->image($this->search($user));

        $this->patchJson("/api/v1/images/{$image->id}/review", ['review_status' => 'meh'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review_status']);
        $this->patchJson("/api/v1/images/{$image->id}/review", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review_status']);

        $this->assertSame(CarImage::REVIEW_PENDING, $image->fresh()->review_status);
    }

    public function test_an_unknown_image_404s(): void
    {
        $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);

        $this->patchJson('/api/v1/images/999999/review', ['review_status' => 'approved'])->assertNotFound();
    }
}
