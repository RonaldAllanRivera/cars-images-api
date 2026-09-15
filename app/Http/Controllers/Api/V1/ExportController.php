<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExportRequest;
use App\Models\CarImage;
use App\Services\Downloads\ExportQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExportController extends Controller
{
    /** Long enough to tap through; short enough that a leaked link goes stale. */
    private const LINK_MINUTES = 5;

    /**
     * Mint a signed link to an export of the filtered set.
     *
     * The app hands this link to the system browser, which follows it with no
     * bearer token - the signature is the credential. So once minted a link is
     * usable by anyone holding it until it expires, which is why a ZIP link is
     * also single-use: replaying one costs up to a hundred Wikimedia fetches.
     */
    public function store(StoreExportRequest $request, ExportQuery $exports): JsonResponse
    {
        Gate::authorize('export', CarImage::class);

        $validated = $request->validated();
        $format = $validated['format'];
        $filters = Arr::except($validated, ['format']);
        $count = $exports->count($filters);

        // A link to an empty file is a worse answer than saying so.
        if ($count === 0) {
            throw ValidationException::withMessages(['format' => 'No images match these filters.']);
        }

        // The cap is a Wikimedia-fetch bound. A CSV fetches nothing and has none.
        if ($format === 'zip' && $count > $exports->zipCap()) {
            throw ValidationException::withMessages([
                'format' => "{$count} images match; a ZIP is limited to {$exports->zipCap()}. Narrow the filter.",
            ]);
        }

        $expiresAt = now()->addMinutes(self::LINK_MINUTES);
        $params = ['format' => $format] + $filters;

        if ($format === 'zip') {
            $nonce = Str::random(40);
            Cache::put("export-nonce:{$nonce}", true, $expiresAt);
            $params['nonce'] = $nonce;
        }

        return response()->json([
            'url' => URL::temporarySignedRoute('exports.download', $expiresAt, $params),
            'expires_at' => $expiresAt->toIso8601String(),
            'count' => $count,
            'format' => $format,
        ], 201);
    }
}
