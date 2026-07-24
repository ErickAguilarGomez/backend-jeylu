<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PriceIncreaseController;

Route::get('/general', [PriceIncreaseController::class, 'show']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/general', [PriceIncreaseController::class, 'upsert']);
    Route::patch('/general/toggle', [PriceIncreaseController::class, 'toggle']);
});
