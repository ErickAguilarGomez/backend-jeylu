<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::get('roles', [UserController::class, 'roles']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('sellers/commissions', [UserController::class, 'getSellersCommissions']);
    Route::put('sellers/{id}/commissions', [UserController::class, 'updateSellerCommission']);
    Route::put('{id}', [UserController::class, 'update']);
    Route::delete('{id}', [UserController::class, 'destroy']);
});
