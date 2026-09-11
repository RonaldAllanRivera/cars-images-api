<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImportsRequest;
use App\Http\Requests\Api\V1\StoreImportRequest;
use App\Http\Resources\Api\V1\ImportResource;
use App\Models\CsvImport;
use App\Services\Imports\CsvImportException;
use App\Services\Imports\CsvQueryImporter;
use App\Services\Imports\ImportCoverage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

    public function store(
        StoreImportRequest $request,
        CsvQueryImporter $importer,
        ImportCoverage $coverage,
    ): JsonResponse {
        Gate::authorize('create', CsvImport::class);

        try {
            $result = $importer->import($request->file('csv_file'), $request->user());
        } catch (CsvImportException $e) {
            /*
             * The importer has already written this to error_events under
             * csv_upload, so nothing is lost by converting it here. Surfacing
             * it as a 422 on the field the client sent puts the message under
             * the file picker rather than in a generic banner - and the
             * messages are written for a human ("Missing required columns:
             * Model. Required: Make, Model, Year.").
             */
            throw ValidationException::withMessages(['csv_file' => $e->getMessage()]);
        }

        $import = $result->csvImport->load('importer')->loadCount('searches');

        return ImportResource::make($import)
            ->withCoverage($coverage->for($import->id))
            ->response()
            ->setStatusCode(201);
    }
}
