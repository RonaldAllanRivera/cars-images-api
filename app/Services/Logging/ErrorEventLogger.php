<?php

namespace App\Services\Logging;

use App\Models\ErrorEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single writer for the pipeline error log.
 *
 * Every call site records through here so that truncation, the per-import cap,
 * and the guarantee that logging never throws are implemented once rather than
 * repeated — and got wrong — at each of the six places that report a failure.
 */
class ErrorEventLogger
{
    public const MAX_MESSAGE = 500;

    public const MAX_EXCEPTION_MESSAGE = 2000;

    public const MAX_DETAIL_VALUE = 1000;

    public const MAX_TRACE_FRAMES = 15;

    /**
     * @param  array{car_search_id?: int|null, csv_import_id?: int|null, car_image_id?: int|null}  $links
     * @param  array<string, mixed>  $details
     */
    public function record(
        string $context,
        Throwable|string $problem,
        array $links = [],
        array $details = [],
        string $severity = 'error',
        ?string $message = null,
    ): void {
        try {
            $importId = $links['csv_import_id'] ?? null;

            if ($importId !== null && ! $this->admitsAnotherEventFor((int) $importId)) {
                return;
            }

            ErrorEvent::create([
                'context' => $context,
                'severity' => $severity,
                'message' => $this->clamp(
                    $message ?? ($problem instanceof Throwable ? $problem->getMessage() : $problem),
                    self::MAX_MESSAGE,
                ),
                'exception_class' => $problem instanceof Throwable ? $problem::class : null,
                'exception_message' => $problem instanceof Throwable
                    ? $this->clamp($problem->getMessage(), self::MAX_EXCEPTION_MESSAGE)
                    : null,
                'trace_excerpt' => $problem instanceof Throwable ? $this->traceExcerpt($problem) : null,
                'details' => $details === [] ? null : $this->clampDetails($details),
                'car_search_id' => $links['car_search_id'] ?? null,
                'csv_import_id' => $importId,
                'car_image_id' => $links['car_image_id'] ?? null,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Logging sits on the failure path, which is the worst possible
            // place for a second failure: a throw here would replace the
            // original problem with a confusing one about the log itself.
            Log::error('Failed to write an error event.', [
                'context' => $context,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether this import is still under its event cap.
     *
     * Counts from the database rather than from an in-memory tally because a
     * bulk run is chunked across separate Livewire requests: a per-instance
     * counter would reset with every chunk and let the cap be exceeded once
     * per chunk. Exactly one suppression notice is written, at the moment the
     * cap is first reached.
     */
    private function admitsAnotherEventFor(int $importId): bool
    {
        $cap = (int) config('cars-images.error_log_max_events_per_import');

        if ($cap <= 0) {
            return true;
        }

        $stored = ErrorEvent::where('csv_import_id', $importId)->count();

        if ($stored < $cap) {
            return true;
        }

        if ($stored === $cap) {
            ErrorEvent::create([
                'context' => ErrorEvent::CONTEXT_CSV_ROW,
                'severity' => 'warning',
                'message' => "Further errors for this import were suppressed after {$cap} events.",
                'csv_import_id' => $importId,
                'occurred_at' => now(),
            ]);
        }

        return false;
    }

    /**
     * The first frames of the trace. The frames nearest the throw carry the
     * diagnosis; the rest is framework plumbing repeated on every row.
     */
    private function traceExcerpt(Throwable $e): string
    {
        $frames = explode("\n", $e->getTraceAsString());

        return implode("\n", array_slice($frames, 0, self::MAX_TRACE_FRAMES));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function clampDetails(array $details): array
    {
        return array_map(
            fn ($value) => is_string($value) ? $this->clamp($value, self::MAX_DETAIL_VALUE) : $value,
            $details,
        );
    }

    /**
     * Truncate to $limit bytes, and scrub anything that is not valid UTF-8.
     *
     * A Wikimedia error response can carry image bytes rather than text, and
     * json_encode rejects invalid UTF-8 — so an unscrubbed excerpt would throw
     * inside the JSON cast, on the failure path.
     */
    private function clamp(string $value, int $limit): string
    {
        $scrubbed = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // mb_strcut, not substr: it respects the byte limit without slicing a
        // multi-byte character in half and reintroducing the invalid UTF-8
        // that was just scrubbed out.
        return strlen($scrubbed) > $limit ? mb_strcut($scrubbed, 0, $limit, 'UTF-8') : $scrubbed;
    }
}
