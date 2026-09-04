<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\LatestFailuresTable;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A shortcut into the log, not a second copy of it: newest first, capped, and
 * every row a link to the full record.
 */
class LatestFailuresTableTest extends TestCase
{
    use RefreshDatabase;

    private function error(string $message, string $occurredAt): ErrorEvent
    {
        return ErrorEvent::create([
            'context' => ErrorEvent::CONTEXT_SEARCH_RUN,
            'severity' => 'error',
            'message' => $message,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_it_shows_the_ten_most_recent_failures_and_no_more(): void
    {
        $user = User::factory()->create();

        $errors = [];
        for ($i = 0; $i < 12; $i++) {
            $errors[] = $this->error("failure {$i}", '2026-09-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).' 09:00:00');
        }

        $newest = array_slice($errors, 2);
        $oldest = array_slice($errors, 0, 2);

        Livewire::actingAs($user)
            ->test(LatestFailuresTable::class)
            ->assertCanSeeTableRecords($newest)
            ->assertCanNotSeeTableRecords($oldest);
    }

    public function test_it_renders_with_no_failures_at_all(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(LatestFailuresTable::class)
            ->assertOk();
    }
}
