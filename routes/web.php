<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CarImageDownloadController;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/car-images/{carImage}/download', CarImageDownloadController::class)
    ->name('car-images.download');
