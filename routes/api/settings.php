<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialMediaController;

Route::get('links', [SocialMediaController::class, 'index']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('links/admin', [SocialMediaController::class, 'adminIndex']);
    Route::post('links', [SocialMediaController::class, 'store']);
    Route::put('links/{id}', [SocialMediaController::class, 'update']);
    Route::delete('links/{id}', [SocialMediaController::class, 'destroy']);
    Route::post('links/sort', [SocialMediaController::class, 'sort']);
});
