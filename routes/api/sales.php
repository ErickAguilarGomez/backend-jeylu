<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaleController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [SaleController::class, 'index'])->middleware('admin');
    Route::post('/', [SaleController::class, 'store']);
});
