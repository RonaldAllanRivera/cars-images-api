<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
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
    });
});
