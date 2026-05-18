<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarImageDownloadController;
use App\Http\Controllers\CarImageBulkDownloadController;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/car-images/{carImage}/download', CarImageDownloadController::class)
    ->name('car-images.download');

Route::middleware(['auth'])->group(function () {
    Route::post('/batch-downloads/zip', [CarImageBulkDownloadController::class, 'zip'])
        ->name('batch-downloads.zip');

    Route::post('/batch-downloads/csv', [CarImageBulkDownloadController::class, 'csv'])
        ->name('batch-downloads.csv');
});
