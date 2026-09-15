<?php

use App\Http\Controllers\CarImageDownloadController;
use App\Http\Controllers\ExportDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/car-images/{carImage}/download', CarImageDownloadController::class)
        ->name('car-images.download');
});

// The panel's bulk ZIP / CSV downloads are still served directly by the Results
// Filament page via Livewire download responses. The mobile app cannot use
// those, so it mints a signed link over the API and follows this route instead.
//
// Signed rather than authenticated: the system browser follows the link with no
// bearer token. Here rather than in api.php so a used or expired link renders as
// a page in the browser tab, not as raw JSON.
Route::get('/exports/download', ExportDownloadController::class)
    ->middleware('signed')
    ->name('exports.download');
