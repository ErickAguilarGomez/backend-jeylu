<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaleController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [SaleController::class, 'index']);
    Route::get('/stats', [SaleController::class, 'stats']);
    Route::get('/commissions/report', [SaleController::class, 'commissionsReport']);
    Route::post('/', [SaleController::class, 'store']);
    Route::put('/{id}', [SaleController::class, 'update']);
});
