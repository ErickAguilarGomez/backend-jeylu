<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SocialMediaController;

// Public route to fetch dynamic WhatsApp settings
Route::get('whatsapp', [SettingController::class, 'getWhatsappSettings']);

// Public route to fetch active social media settings
Route::get('social-media', [SocialMediaController::class, 'index']);

// Admin-only route to update configurations
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('whatsapp', [SettingController::class, 'updateWhatsappSettings']);
    
    // Admin social media management
    Route::get('social-media/admin', [SocialMediaController::class, 'adminIndex']);
    Route::post('social-media', [SocialMediaController::class, 'store']);
    Route::put('social-media/{id}', [SocialMediaController::class, 'update']);
    Route::delete('social-media/{id}', [SocialMediaController::class, 'destroy']);
    Route::post('social-media/sort', [SocialMediaController::class, 'sort']);
});
