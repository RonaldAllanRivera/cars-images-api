<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\PipelineSeries;
use Illuminate\Http\JsonResponse;

class HealthSeriesController extends Controller
{
    public function __invoke(PipelineSeries $series): JsonResponse
    {
        $data = $series->build();

        // PHP encodes an empty array as `[]`, and `series` is a map: a quiet
        // fortnight must still arrive as `{}`, or the client's contract
        // rejects the whole response on exactly the good days.
        $data['errors_by_context']['series'] = (object) $data['errors_by_context']['series'];

        return response()->json(['data' => $data]);
    }
}
