<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PurchaseOrderController;

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [PurchaseOrderController::class, 'index']);
});
