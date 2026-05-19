<?php

use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(base_path('routes/api/auth.php'));
Route::prefix('products')->group(base_path('routes/api/products.php'));
Route::prefix('categories')->group(base_path('routes/api/categories.php'));
Route::prefix('stores')->group(base_path('routes/api/stores.php'));
Route::prefix('users')->group(base_path('routes/api/users.php'));
Route::prefix('sales')->group(base_path('routes/api/sales.php'));
