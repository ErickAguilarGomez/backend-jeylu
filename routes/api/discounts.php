<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DiscountController;

Route::get('/general', [DiscountController::class, 'show']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/general', [DiscountController::class, 'upsert']);
    Route::patch('/general/toggle', [DiscountController::class, 'toggle']);
});
