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
Route::get('external-links', [SocialMediaController::class, 'index']);

// Admin-only route to update configurations
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    
    // Admin social media management
    Route::get('external-links/admin', [SocialMediaController::class, 'adminIndex']);
    Route::post('external-links', [SocialMediaController::class, 'store']);
    Route::put('external-links/{id}', [SocialMediaController::class, 'update']);
    Route::delete('external-links/{id}', [SocialMediaController::class, 'destroy']);
    Route::post('external-links/sort', [SocialMediaController::class, 'sort']);
});
