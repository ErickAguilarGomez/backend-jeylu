<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentMethodController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index']);
    Route::post('/', [PaymentMethodController::class, 'store']);
    Route::put('{id}', [PaymentMethodController::class, 'update']);
    Route::post('{id}/toggle', [PaymentMethodController::class, 'toggle']);
});
