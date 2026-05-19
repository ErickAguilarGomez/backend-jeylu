<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::get('roles', [UserController::class, 'roles'])->middleware('admin');
    Route::post('/', [UserController::class, 'store'])->middleware('admin');
    Route::put('{id}', [UserController::class, 'update'])->middleware('admin');
    Route::delete('{id}', [UserController::class, 'destroy'])->middleware('admin');
});
