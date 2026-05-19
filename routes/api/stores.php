<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StoreController;

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [StoreController::class, 'index']);
    Route::post('/', [StoreController::class, 'store']);
    Route::put('{id}', [StoreController::class, 'update']);
    Route::delete('{id}', [StoreController::class, 'destroy']);

    Route::get('{id}/employees', [StoreController::class, 'getEmployees']);
    Route::post('{id}/employees', [StoreController::class, 'assignEmployee']);
    Route::delete('{id}/employees/{userId}', [StoreController::class, 'unassignEmployee']);
});
