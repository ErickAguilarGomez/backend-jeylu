<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialMediaController;

// Test route to diagnose server routing without database queries
Route::get('test-settings', function() {
    return response()->json([
        'success' => true,
        'message' => 'Settings routing is working correctly!'
    ]);
});

// Public route to fetch active social media settings
Route::get('links', [SocialMediaController::class, 'index']);

// Admin-only route to update configurations
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    
    // Admin social media management
    Route::get('links/admin', [SocialMediaController::class, 'adminIndex']);
    Route::post('links', [SocialMediaController::class, 'store']);
    Route::put('links/{id}', [SocialMediaController::class, 'update']);
    Route::delete('links/{id}', [SocialMediaController::class, 'destroy']);
    Route::post('links/sort', [SocialMediaController::class, 'sort']);
});
