<?php

namespace App\Http\Controllers;

use App\Models\CarImage;
use App\Services\Downloads\BatchCsvExporter;
use App\Services\Downloads\BatchZipBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarImageBulkDownloadController extends Controller
{
    public function zip(
        Request $request,
        BatchZipBuilder $builder,
    ): BinaryFileResponse {
        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['integer'],
        ]);

        $images = CarImage::with('search')
            ->whereIn('id', $data['image_ids'])
            ->orderBy('car_search_id')
            ->orderBy('id')
            ->get();

        $tmpPath = tempnam(sys_get_temp_dir(), 'cars-batch-');
        $builder->buildToFile($images, $tmpPath);

        $filename = 'cars-batch-' . now()->format('Ymd-His') . '.zip';

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function csv(
        Request $request,
        BatchCsvExporter $exporter,
    ): StreamedResponse {
        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['integer'],
        ]);

        $images = CarImage::with('search')
            ->whereIn('id', $data['image_ids'])
            ->orderBy('car_search_id')
            ->orderBy('id')
            ->get();

        $filename = 'cars-batch-' . now()->format('Ymd-His') . '.csv';

        return response()->stream(
            function () use ($exporter, $images) {
                $handle = fopen('php://output', 'w');
                $exporter->streamTo($handle, $images);
                fclose($handle);
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ],
        );
    }
}
