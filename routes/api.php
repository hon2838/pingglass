<?php

use App\Http\Controllers\Public\MonitorController;
use App\Http\Controllers\Public\TargetController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->name('api.public.')->middleware('throttle:60,1')->group(function () {
    Route::get('/status', [MonitorController::class, 'apiStatus'])->name('status');
    Route::get('/categories', [MonitorController::class, 'apiCategories'])->name('categories');
    Route::get('/targets/{target:slug}/metrics', [TargetController::class, 'metrics'])->name('targets.metrics');
    Route::get('/targets/{target:slug}/csv', [TargetController::class, 'csv'])->name('targets.csv');
});
