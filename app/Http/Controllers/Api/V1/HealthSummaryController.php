<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\PipelineHealthSummary;
use Illuminate\Http\JsonResponse;

class HealthSummaryController extends Controller
{
    public function __invoke(PipelineHealthSummary $summary): JsonResponse
    {
        return response()->json(['data' => $summary->build()]);
    }
}
