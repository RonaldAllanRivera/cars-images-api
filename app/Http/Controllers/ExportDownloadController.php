<?php

namespace App\Http\Controllers;

use App\Models\CarImage;
use App\Services\Downloads\BatchCsvExporter;
use App\Services\Downloads\BatchZipBuilder;
use App\Services\Downloads\ExportQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a signed export link.
 *
 * No authentication: the signature is the credential, because the system
 * browser follows this link with no bearer token. The `signed` middleware on
 * the route has already rejected a tampered or expired URL before this runs.
 */
class ExportDownloadController
{
    /** Parameters that belong to the link, not to the image filter. */
    private const LINK_PARAMS = ['format', 'nonce', 'expires', 'signature'];

    public function __invoke(
        Request $request,
        ExportQuery $exports,
        BatchCsvExporter $csv,
        BatchZipBuilder $zip,
    ): Response {
        $format = $request->query('format');
        $filters = Arr::except($request->query(), self::LINK_PARAMS);

        return $format === 'zip'
            ? $this->zip($request, $filters, $exports, $zip)
            : $this->csv($filters, $exports, $csv);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function csv(array $filters, ExportQuery $exports, BatchCsvExporter $csv): Response
    {
        $images = $exports->images($filters);

        return response()->streamDownload(function () use ($csv, $images) {
            $handle = fopen('php://output', 'w');
            $csv->streamTo($handle, $images);
            fclose($handle);
        }, 'cars-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function zip(Request $request, array $filters, ExportQuery $exports, BatchZipBuilder $zip): Response
    {
        /*
         * Single-use. Not atomic on the file cache store - pull is a get then a
         * forget, so two requests in the same instant could both pass. The threat
         * is a leaked link replayed later, after the nonce is gone, not the user
         * racing themselves.
         */
        if (! Cache::pull('export-nonce:'.$request->query('nonce'))) {
            abort(410, 'This download link has already been used. Export again from the app.');
        }

        /*
         * Recount rather than trust the mint. Images can arrive in the minutes
         * between, and a ZIP that quietly grew past the cap would exceed
         * max_execution_time.
         */
        if ($exports->count($filters) > $exports->zipCap()) {
            abort(422, 'More images match now than a ZIP allows. Export again from the app with a narrower filter.');
        }

        $images = $exports->images($filters);
        $path = tempnam(sys_get_temp_dir(), 'cars-export-');

        if ($zip->buildToFile($images, $path) === 0) {
            @unlink($path);

            // Matches the panel's "No images could be downloaded" notification:
            // an empty archive would look like a successful export of nothing.
            abort(502, 'None of the images could be fetched from Wikimedia. Try again in a moment.');
        }

        // As Results::zipDownload() does. A side effect on a GET is ordinarily
        // wrong; the single-use nonce is what makes it fire once per mint.
        CarImage::whereIn('id', $images->pluck('id'))->update(['download_status' => 'downloaded']);

        return response()
            ->download($path, 'cars-'.now()->format('Ymd-His').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
