<?php

use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\IncidentController as AdminIncidentController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TargetController as AdminTargetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Public\MonitorController;
use App\Http\Controllers\Public\TargetController as PublicTargetController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MonitorController::class, 'index'])->name('home');
Route::get('/monitor', [MonitorController::class, 'index'])->name('monitor.index');
Route::get('/monitor/{category:slug}', [MonitorController::class, 'category'])->name('monitor.category');
Route::get('/target/{target:slug}', [PublicTargetController::class, 'show'])->name('target.show');

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware(['admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::resource('categories', AdminCategoryController::class)->except(['show']);
    Route::post('categories/{category}/toggle', [AdminCategoryController::class, 'toggle'])->name('categories.toggle');

    Route::resource('targets', AdminTargetController::class)->except(['show']);
    Route::post('targets/test', [AdminTargetController::class, 'test'])
        ->name('targets.test')
        ->middleware('throttle:10,1');
    Route::post('targets/{target}/toggle', [AdminTargetController::class, 'toggle'])->name('targets.toggle');

    Route::get('incidents', [AdminIncidentController::class, 'index'])->name('incidents.index');
    Route::post('incidents/{incident}/close', [AdminIncidentController::class, 'close'])->name('incidents.close');

    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('health', [HealthController::class, 'index'])->name('health.index');
});
