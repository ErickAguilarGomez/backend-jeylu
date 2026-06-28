<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;

Route::get('/', [ProductController::class, 'index']);
Route::get('all', [ProductController::class, 'all']);
Route::get('best-sellers', [ProductController::class, 'bestSellers']);
Route::get('{sku}', [ProductController::class, 'show']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::delete('images', [ProductController::class, 'destroyImage']);
    Route::post('/', [ProductController::class, 'store']);
    Route::post('{sku}', [ProductController::class, 'update']);
    Route::delete('{sku}', [ProductController::class, 'destroy']);
    Route::post('{sku}/restore', [ProductController::class, 'restore']);
});
