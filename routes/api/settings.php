<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialMediaController;
use App\Http\Controllers\WhatsappNumberController;

Route::get('links', [SocialMediaController::class, 'index']);
Route::get('whatsapp-numbers', [WhatsappNumberController::class, 'index']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('links/admin', [SocialMediaController::class, 'adminIndex']);
    Route::post('links', [SocialMediaController::class, 'store']);
    Route::put('links/{id}', [SocialMediaController::class, 'update']);
    Route::delete('links/{id}', [SocialMediaController::class, 'destroy']);
    Route::post('links/sort', [SocialMediaController::class, 'sort']);

    Route::get('whatsapp-numbers/admin', [WhatsappNumberController::class, 'adminIndex']);
    Route::post('whatsapp-numbers', [WhatsappNumberController::class, 'store']);
    Route::put('whatsapp-numbers/{id}', [WhatsappNumberController::class, 'update']);
    Route::delete('whatsapp-numbers/{id}', [WhatsappNumberController::class, 'destroy']);
    Route::patch('whatsapp-numbers/{id}/toggle', [WhatsappNumberController::class, 'toggleActive']);
    Route::post('whatsapp-numbers/reorder', [WhatsappNumberController::class, 'reorder']);
});
