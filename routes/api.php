<?php

use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(base_path('routes/api/auth.php'));
Route::prefix('products')->group(base_path('routes/api/products.php'));
Route::prefix('categories')->group(base_path('routes/api/categories.php'));
Route::prefix('stores')->group(base_path('routes/api/stores.php'));
Route::prefix('users')->group(base_path('routes/api/users.php'));
Route::prefix('sales')->group(base_path('routes/api/sales.php'));
Route::prefix('banners')->group(base_path('routes/api/banners.php'));
Route::prefix('settings')->group(base_path('routes/api/settings.php'));
Route::prefix('purchase-orders')->group(base_path('routes/api/purchase-orders.php'));
Route::prefix('discounts')->group(base_path('routes/api/discounts.php'));


