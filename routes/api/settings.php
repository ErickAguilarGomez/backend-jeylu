<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialMediaController;

// Public route to fetch active social media settings
Route::get('socials', [SocialMediaController::class, 'index']);

// Admin-only route to update configurations
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    
    // Admin social media management
    Route::get('socials/admin', [SocialMediaController::class, 'adminIndex']);
    Route::post('socials', [SocialMediaController::class, 'store']);
    Route::put('socials/{id}', [SocialMediaController::class, 'update']);
    Route::delete('socials/{id}', [SocialMediaController::class, 'destroy']);
    Route::post('socials/sort', [SocialMediaController::class, 'sort']);
});
