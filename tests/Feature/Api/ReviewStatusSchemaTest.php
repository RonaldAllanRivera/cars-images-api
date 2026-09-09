<?php

namespace Tests\Feature\Api;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

class ReviewStatusSchemaTest extends ApiTestCase
{
    public function test_a_new_image_is_pending_review_with_no_reviewer(): void
    {
        $user = User::factory()->create();

        $image = $this->image($this->search($user))->refresh();

        $this->assertSame(CarImage::REVIEW_PENDING, $image->review_status);
        $this->assertNull($image->reviewed_by);
        $this->assertNull($image->reviewed_at);
        $this->assertNull($image->reviewer);
    }

    public function test_reviewer_is_a_user_relation_and_reviewed_at_is_a_date(): void
    {
        $user = User::factory()->create();

        $image = $this->image($this->search($user), [
            'review_status' => CarImage::REVIEW_APPROVED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->assertTrue($image->reviewer->is($user));
        $this->assertInstanceOf(CarbonInterface::class, $image->reviewed_at);
    }

    public function test_deleting_the_reviewer_keeps_the_verdict(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $image = $this->image($this->search($owner), [
            'review_status' => CarImage::REVIEW_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $reviewer->delete();

        $image->refresh();
        $this->assertSame(CarImage::REVIEW_REJECTED, $image->review_status);
        $this->assertNull($image->reviewed_by);
    }

    public function test_policies_let_any_user_see_and_review_everything(): void
    {
        $user = User::factory()->create();
        $search = $this->search(User::factory()->create());
        $image = $this->image($search);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', CarImage::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $image));
        $this->assertTrue(Gate::forUser($user)->allows('update', $image));
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', CarSearch::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $search));
        $this->assertTrue(Gate::forUser($user)->allows('create', CarSearch::class));
    }
}
