<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BannerController;

// Public route to fetch active banners for the Home page slider
Route::get('active', [BannerController::class, 'activeList']);

// Admin-only protected CRUD routes
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [BannerController::class, 'index']);
    Route::post('/', [BannerController::class, 'store']);
    Route::post('reorder', [BannerController::class, 'reorder']);
    Route::post('{id}', [BannerController::class, 'update']);
    Route::delete('{id}', [BannerController::class, 'destroy']);
    Route::patch('{id}/toggle', [BannerController::class, 'toggleActive']);
});
