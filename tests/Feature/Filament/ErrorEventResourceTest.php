<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ErrorEventResource;
use App\Filament\Resources\ErrorEventResource\Pages\ListErrorEvents;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ErrorEventResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_log_page_lists_recorded_events(): void
    {
        $user = User::factory()->create();
        $event = $this->event(ErrorEvent::CONTEXT_SEARCH_RUN);

        Livewire::actingAs($user)
            ->test(ListErrorEvents::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$event]);
    }

    public function test_the_log_can_be_filtered_to_one_context(): void
    {
        $user = User::factory()->create();
        $searchFailure = $this->event(ErrorEvent::CONTEXT_SEARCH_RUN);
        $downloadFailure = $this->event(ErrorEvent::CONTEXT_IMAGE_DOWNLOAD);

        Livewire::actingAs($user)
            ->test(ListErrorEvents::class)
            ->filterTable('context', ErrorEvent::CONTEXT_IMAGE_DOWNLOAD)
            ->assertCanSeeTableRecords([$downloadFailure])
            ->assertCanNotSeeTableRecords([$searchFailure]);
    }

    public function test_the_log_is_read_only(): void
    {
        $this->assertFalse(ErrorEventResource::canCreate());
        $this->assertFalse(ErrorEventResource::canEdit($this->event()));
    }

    public function test_the_navigation_badge_counts_only_the_last_day(): void
    {
        $this->event(occurredAt: now()->subHours(2));
        $this->event(occurredAt: now()->subDays(3));

        $this->assertSame('1', ErrorEventResource::getNavigationBadge());
    }

    public function test_the_navigation_badge_is_hidden_when_nothing_is_wrong(): void
    {
        $this->assertNull(ErrorEventResource::getNavigationBadge());
    }

    public function test_pruning_from_the_page_deletes_only_stale_events(): void
    {
        config(['cars-images.error_log_retention_days' => 30]);

        $user = User::factory()->create();
        $stale = $this->event(occurredAt: now()->subDays(31));
        $fresh = $this->event(occurredAt: now()->subDays(1));

        Livewire::actingAs($user)
            ->test(ListErrorEvents::class)
            ->callAction('prune');

        $this->assertDatabaseMissing('error_events', ['id' => $stale->id]);
        $this->assertDatabaseHas('error_events', ['id' => $fresh->id]);
    }

    private function event(string $context = ErrorEvent::CONTEXT_SEARCH_RUN, ?\DateTimeInterface $occurredAt = null): ErrorEvent
    {
        return ErrorEvent::create([
            'context' => $context,
            'severity' => 'error',
            'message' => 'something broke',
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
