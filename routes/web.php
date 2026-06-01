<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarImageDownloadController;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/car-images/{carImage}/download', CarImageDownloadController::class)
        ->name('car-images.download');
});

// Bulk ZIP / CSV downloads are served directly by the Results Filament page
// (App\Filament\Pages\Results) via Livewire download responses, so they need
// no dedicated routes or controller.
