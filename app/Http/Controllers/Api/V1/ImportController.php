<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImportsRequest;
use App\Http\Resources\Api\V1\ImportResource;
use App\Models\CsvImport;
use App\Services\Imports\ImportCoverage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ImportController extends Controller
{
    public function index(ListImportsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CsvImport::class);

        $imports = CsvImport::query()
            ->with('importer')
            ->withCount('searches')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ImportResource::collection($imports);
    }

    public function show(CsvImport $import, ImportCoverage $coverage): ImportResource
    {
        Gate::authorize('view', $import);

        return ImportResource::make($import->load('importer')->loadCount('searches'))
            ->withCoverage($coverage->for($import->id));
    }
}
