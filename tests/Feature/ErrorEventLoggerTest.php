<?php

namespace Tests\Feature;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\ErrorEvent;
use App\Models\User;
use App\Services\Logging\ErrorEventLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ErrorEventLoggerTest extends TestCase
{
    use RefreshDatabase;

    private function logger(): ErrorEventLogger
    {
        return app(ErrorEventLogger::class);
    }

    public function test_records_a_throwable_with_its_class_message_and_trace(): void
    {
        $this->logger()->record('search_run', new RuntimeException('Wikimedia returned nothing'));

        $event = ErrorEvent::sole();

        $this->assertSame('search_run', $event->context);
        $this->assertSame('error', $event->severity);
        $this->assertSame(RuntimeException::class, $event->exception_class);
        $this->assertSame('Wikimedia returned nothing', $event->exception_message);
        $this->assertStringContainsString('ErrorEventLoggerTest', $event->trace_excerpt);
        $this->assertNotNull($event->occurred_at);
    }

    public function test_records_a_plain_string_problem_without_exception_columns(): void
    {
        $this->logger()->record('csv_row', 'Year 1899 is out of range');

        $event = ErrorEvent::sole();

        $this->assertSame('Year 1899 is out of range', $event->message);
        $this->assertNull($event->exception_class);
        $this->assertNull($event->exception_message);
        $this->assertNull($event->trace_excerpt);
    }

    public function test_stores_links_and_details(): void
    {
        $user = User::factory()->create();
        $import = $this->import($user);
        $search = $this->search($user, $import);

        $this->logger()->record(
            'image_download',
            'Fetch failed',
            links: ['car_search_id' => $search->id, 'csv_import_id' => $import->id],
            details: ['http_status' => 404, 'url' => 'https://upload.wikimedia.org/a.jpg'],
        );

        $event = ErrorEvent::sole();

        $this->assertSame($search->id, $event->car_search_id);
        $this->assertSame($import->id, $event->csv_import_id);
        $this->assertSame(404, $event->details['http_status']);
        $this->assertSame('https://upload.wikimedia.org/a.jpg', $event->details['url']);
    }

    public function test_truncates_an_oversized_exception_message(): void
    {
        $this->logger()->record('search_run', new RuntimeException(str_repeat('x', 5000)));

        $this->assertSame(ErrorEventLogger::MAX_EXCEPTION_MESSAGE, strlen(ErrorEvent::sole()->exception_message));
    }

    public function test_truncates_an_oversized_detail_value(): void
    {
        $this->logger()->record('search_run', 'Blocked', details: ['response_excerpt' => str_repeat('y', 5000)]);

        $this->assertSame(ErrorEventLogger::MAX_DETAIL_VALUE, strlen(ErrorEvent::sole()->details['response_excerpt']));
    }

    public function test_keeps_only_the_first_frames_of_a_trace(): void
    {
        $this->logger()->record('search_run', $this->deeplyNestedException(40));

        $this->assertLessThanOrEqual(
            ErrorEventLogger::MAX_TRACE_FRAMES,
            substr_count(ErrorEvent::sole()->trace_excerpt, "\n") + 1,
        );
    }

    public function test_a_failing_write_does_not_propagate_to_the_caller(): void
    {
        // Fail the insert itself rather than dropping the table. A test cannot
        // undo DDL: MySQL commits it implicitly, and on sqlite :memory: the
        // migrate:fresh that would rebuild the table ends in a VACUUM, which
        // SQLite refuses inside the transaction RefreshDatabase holds open.
        // Either way the damage outlives the test and every later test runs
        // against a schema this one destroyed.
        ErrorEvent::creating(function () {
            throw new QueryException(
                'sqlite',
                'insert into "error_events" ("context") values (?)',
                ['search_run'],
                new PDOException('no such table: error_events'),
            );
        });

        $this->logger()->record('search_run', new RuntimeException('the original failure'));

        // Reaching here at all is the assertion: a broken log must not break
        // the run it is reporting on.
        $this->assertTrue(true);
    }

    public function test_binary_detail_values_are_stored_rather_than_breaking_the_write(): void
    {
        // A Wikimedia error response can carry image bytes rather than text.
        // json_encode rejects invalid UTF-8, so an unsanitised excerpt would
        // throw inside the JSON cast — on the failure path, of all places.
        $this->logger()->record('image_download', 'Fetch failed', details: [
            'response_excerpt' => "\xC3\x28 binary \xFF\xFE",
        ]);

        $event = ErrorEvent::sole();

        $this->assertStringContainsString('binary', $event->details['response_excerpt']);
    }

    public function test_caps_the_events_stored_for_one_import_and_says_so_once(): void
    {
        config(['cars-images.error_log_max_events_per_import' => 3]);

        $user = User::factory()->create();
        $import = $this->import($user);

        foreach (range(1, 10) as $i) {
            $this->logger()->record('csv_row', "Row {$i} rejected", links: ['csv_import_id' => $import->id]);
        }

        $events = ErrorEvent::where('csv_import_id', $import->id)->get();

        $this->assertCount(4, $events, 'Three real events plus exactly one suppression notice.');
        $this->assertSame(1, $events->where('severity', 'warning')->count());
        $this->assertStringContainsString('suppressed', $events->last()->message);
    }

    public function test_the_cap_is_recounted_from_the_database_not_from_process_state(): void
    {
        config(['cars-images.error_log_max_events_per_import' => 2]);

        $user = User::factory()->create();
        $import = $this->import($user);

        // Two separate logger instances stand in for two Livewire chunks of a
        // bulk run: an in-memory counter would reset between them and let the
        // cap be exceeded twice over.
        app(ErrorEventLogger::class)->record('csv_row', 'first', links: ['csv_import_id' => $import->id]);
        app(ErrorEventLogger::class)->record('csv_row', 'second', links: ['csv_import_id' => $import->id]);
        app(ErrorEventLogger::class)->record('csv_row', 'third', links: ['csv_import_id' => $import->id]);
        app(ErrorEventLogger::class)->record('csv_row', 'fourth', links: ['csv_import_id' => $import->id]);

        $this->assertSame(3, ErrorEvent::where('csv_import_id', $import->id)->count());
    }

    public function test_events_without_an_import_are_not_capped(): void
    {
        config(['cars-images.error_log_max_events_per_import' => 2]);

        foreach (range(1, 5) as $i) {
            $this->logger()->record('search_run', "failure {$i}");
        }

        $this->assertSame(5, ErrorEvent::count());
    }

    public function test_an_error_event_survives_deletion_of_the_import_it_describes(): void
    {
        $user = User::factory()->create();
        $import = $this->import($user);

        $this->logger()->record('csv_upload', 'Missing required columns', links: ['csv_import_id' => $import->id]);

        $import->delete();

        $event = ErrorEvent::sole();
        $this->assertNull($event->csv_import_id);
        $this->assertSame('Missing required columns', $event->message);
    }

    private function import(User $user): CsvImport
    {
        return CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 10,
            'unique_combos' => 10,
            'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);
    }

    private function search(User $user, CsvImport $import): CarSearch
    {
        return CarSearch::create([
            'make' => 'Toyota',
            'model' => 'Corolla',
            'from_year' => 2019,
            'to_year' => 2019,
            'images_per_year' => 5,
            'status' => 'pending',
            'requested_by' => $user->id,
            'csv_import_id' => $import->id,
        ]);
    }

    private function deeplyNestedException(int $depth): RuntimeException
    {
        if ($depth <= 0) {
            return new RuntimeException('deep');
        }

        return $this->deeplyNestedException($depth - 1);
    }
}
