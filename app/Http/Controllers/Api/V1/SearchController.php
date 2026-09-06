<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\WikimediaBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImagesRequest;
use App\Http\Requests\Api\V1\ListSearchesRequest;
use App\Http\Requests\Api\V1\StoreSearchRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Http\Resources\Api\V1\SearchResource;
use App\Models\CarSearch;
use App\Services\Images\CarImageSearchService;
use App\Services\Search\RunSearchQueryAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Throwable;

class SearchController extends Controller
{
    public function index(ListSearchesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CarSearch::class);

        $status = $request->validated()['status'] ?? null;

        $searches = CarSearch::query()
            ->withCount('images')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return SearchResource::collection($searches);
    }

    public function show(CarSearch $search): SearchResource
    {
        Gate::authorize('view', $search);

        return SearchResource::make($search->loadCount('images'));
    }

    public function images(ListImagesRequest $request, CarSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $search);

        $images = $request->apply($search->images()->getQuery())
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ImageResource::collection($images);
    }

    /**
     * Create a search and run it inside this request.
     *
     * There is no queue worker on shared hosting, so this is the same inline
     * path the admin's single-row action takes - through RunSearchQueryAction,
     * which marks the row `failed` and writes an error_events row before
     * re-throwing. StoreSearchRequest caps the size so it finishes in time.
     */
    public function store(
        StoreSearchRequest $request,
        CarImageSearchService $service,
        RunSearchQueryAction $runner,
    ): JsonResponse {
        Gate::authorize('create', CarSearch::class);

        $arguments = $request->searchArguments();

        // Same dedupe as the admin form. An identical completed search is
        // handed back rather than re-run - which also spares Wikimedia.
        $existing = $service->findExistingCompletedSearch(...$arguments);

        if ($existing !== null) {
            return $this->searchResponse($existing, 200);
        }

        $search = $service->createSearch($request->user(), ...$arguments);

        try {
            $runner->execute($search);
        } catch (WikimediaBlockedException $e) {
            return $this->searchResponse($search, 503, [
                'message' => 'Wikimedia is rate-limiting this server. Try again later.',
                'retry_after_seconds' => $e->retryAfterSeconds,
            ])->withHeaders(array_filter(['Retry-After' => $e->retryAfterSeconds]));
        } catch (Throwable) {
            return $this->searchResponse($search, 502, [
                'message' => 'The search failed. The reason is in the error log.',
            ]);
        }

        return $this->searchResponse($search, 201);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function searchResponse(CarSearch $search, int $status, array $extra = []): JsonResponse
    {
        $search->load('images')->loadCount('images');

        return response()->json($extra + ['data' => SearchResource::make($search)->resolve()], $status);
    }
}
