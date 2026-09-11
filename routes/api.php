<?php

use App\Auth\TokenAbilities;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\ErrorController;
use App\Http\Controllers\Api\V1\HealthSummaryController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\ReviewImageController;
use App\Http\Controllers\Api\V1\SearchController;
use Illuminate\Support\Facades\Route;

/*
 * Versioned from the first commit. The mobile client is built against /v1;
 * a breaking change ships as /v2 beside it rather than in place.
 *
 * The `api` middleware group applies no throttle of its own, so every limit
 * in force is the one named on the route.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    // The one route the open internet can reach - hence the tightest limit.
    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', LogoutController::class)
            ->middleware('throttle:60,1')
            ->name('auth.logout');

        Route::get('auth/me', MeController::class)
            ->middleware('throttle:120,1')
            ->name('auth.me');

        Route::middleware(['ability:'.TokenAbilities::SEARCH_READ, 'throttle:120,1'])->group(function () {
            Route::get('images', [ImageController::class, 'index'])->name('images.index');
            Route::get('images/{image}', [ImageController::class, 'show'])->name('images.show');

            Route::get('searches', [SearchController::class, 'index'])->name('searches.index');
            Route::get('searches/{search}', [SearchController::class, 'show'])->name('searches.show');
            Route::get('searches/{search}/images', [SearchController::class, 'images'])->name('searches.images');
        });

        Route::middleware(['ability:'.TokenAbilities::IMPORTS_READ, 'throttle:120,1'])->group(function () {
            Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
            Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        });

        // Reaches Wikimedia, which has blocked this app before: the tightest
        // authenticated limit, on top of the size caps in StoreSearchRequest.
        Route::post('searches', [SearchController::class, 'store'])
            ->middleware(['ability:'.TokenAbilities::SEARCH_WRITE, 'throttle:10,1'])
            ->name('searches.store');

        // Seeds up to csv_import_max_combos searches, each of which is a future
        // Wikimedia call, so the tightest authenticated limit - and
        // imports:write is deliberately outside the web build's requested scope.
        Route::post('imports', [ImportController::class, 'store'])
            ->middleware(['ability:'.TokenAbilities::IMPORTS_WRITE, 'throttle:10,1'])
            ->name('imports.store');

        Route::patch('images/{image}/review', ReviewImageController::class)
            ->middleware(['ability:'.TokenAbilities::REVIEW_WRITE, 'throttle:60,1'])
            ->name('images.review');

        Route::middleware(['ability:'.TokenAbilities::ERRORS_READ, 'throttle:120,1'])->group(function () {
            Route::get('health/summary', HealthSummaryController::class)->name('health.summary');
            Route::get('errors', [ErrorController::class, 'index'])->name('errors.index');
        });
    });
});
