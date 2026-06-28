<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class EcommerceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders([
            'Referer' => 'http://localhost',
            'Accept' => 'application/json',
        ]);
    }

    public function test_auth_login_validation_and_success()
    {
        // 1. Invalid login
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@jeilu.com',
            'password' => 'wrongpassword'
        ]);
        $response->assertStatus(401);

        // 2. Valid login
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@jeilu.com',
            'password' => 'Jeilu2026!'
        ]);
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['success', 'user' => ['id', 'name', 'email', 'role_id'], 'token']);
    }

    public function test_csrf_token_endpoint()
    {
        $response = $this->getJson('/api/auth/csrf-token');
        $response->assertStatus(200)
                 ->assertJsonStructure(['token']);
    }

    // public function test_auth_registration_with_email_uniqueness()
    // {
    //     // 1. Successful register
    //     $response = $this->postJson('/api/auth/register', [
    //         'name' => 'Nuevo Cliente',
    //         'email' => 'cliente@jeilu.com',
    //         'password' => 'password123',
    //         'password_confirmation' => 'password123'
    //     ]);
    //     $response->assertStatus(200)
    //              ->assertJsonPath('success', true);
    //
    //     // 2. Duplicate email register (should be blocked by our unique validator)
    //     $response = $this->postJson('/api/auth/register', [
    //         'name' => 'Cliente Duplicado',
    //         'email' => 'cliente@jeilu.com',
    //         'password' => 'password123',
    //         'password_confirmation' => 'password123'
    //     ]);
    //     $response->assertStatus(422)
    //              ->assertJsonValidationErrors(['email']);
    // }

    public function test_categories_endpoints()
    {
        $admin = User::find(1);

        // 1. Get Categories (without auth works since index is public)
        $response = $this->getJson('/api/categories');
        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data']);

        // 2. Create Category
        $response = $this->actingAs($admin)->postJson('/api/categories', [
            'name' => 'Ropa Deportiva',
            'description' => 'Zapatillas y ropa deportiva'
        ]);
        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        // 3. Update Category with uniqueness validation
        $response = $this->actingAs($admin)->putJson('/api/categories/1', [
            'name' => 'Calzado Premium',
            'description' => 'Zapatillas caras'
        ]);
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // 4. Update Category 1 to 'Ropa Deportiva' which we just created in step 2 (should fail)
        $response = $this->actingAs($admin)->putJson('/api/categories/1', [
            'name' => 'Ropa Deportiva'
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name']);
    }

    public function test_stores_endpoints()
    {
        $admin = User::find(1);

        // 1. Get Stores
        $response = $this->actingAs($admin)->getJson('/api/stores');
        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data']);

        // 2. Create Store
        $response = $this->actingAs($admin)->postJson('/api/stores', [
            'name' => 'Tienda Norte',
            'address' => 'Av. Alfredo Mendiola 1234',
            'type' => 'tienda'
        ]);
        $response->assertStatus(201);

        // 3. Update Store with uniqueness validation
        $response = $this->actingAs($admin)->putJson('/api/stores/1', [
            'name' => 'Tienda Sur',
            'address' => 'Av. Tomas Marsano 4321',
            'type' => 'tienda'
        ]);
        $response->assertStatus(200);

        // Try to update sur store to north store name (duplicate)
        $response = $this->actingAs($admin)->putJson('/api/stores/1', [
            'name' => 'Tienda Norte',
            'address' => 'Av. Tomas Marsano 4321',
            'type' => 'tienda'
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name']);
    }

    public function test_products_endpoints()
    {
        $admin = User::find(1);

        // 1. Create Product (requires admin and exists rules)
        $response = $this->actingAs($admin)->postJson('/api/products', [
            'base_sku' => 'TSHIRT-NIKE',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Polo Nike Run',
            'price' => 89.90,
            'variants' => [
                ['size' => 'M', 'color' => 'Negro', 'stocks' => [1 => 10]],
                ['size' => 'L', 'color' => 'Negro', 'stocks' => [1 => 5]]
            ]
        ]);
        $response->assertStatus(201);

        // 2. Get Products /api/products (Public index method)
        $response = $this->getJson('/api/products');
        $response->assertStatus(200)
                 ->assertJsonPath('data.0.is_available', true);

        // 3. Get Products /api/products/all (Contains total_stock)
        $response = $this->getJson('/api/products/all');
        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data' => [['total_stock']]]);

        // 4. Show Product by SKU
        $response = $this->getJson('/api/products/TSHIRT-NIKE');
        $response->assertStatus(200)
                 ->assertJsonPath('data.sku', 'TSHIRT-NIKE')
                 ->assertJsonPath('data.category_name', 'Calzado Urbano')
                 ->assertJsonPath('data.store_name', 'Tienda Central JEILU (Central)')
                 ->assertJsonPath('data.store_address', 'Av. Conquistadores 1240, San Isidro')
                 ->assertJsonPath('data.store_phone', '+51 1 4219999');

        // 5. Update Product (exists check validation)
        $response = $this->actingAs($admin)->postJson('/api/products/TSHIRT-NIKE', [
            'category_id' => 999, // Invalid category_id
            'store_id' => 1,
            'name' => 'Polo Nike Run Modificado',
            'price' => 99.90,
            'variants' => [
                ['size' => 'M', 'color' => 'Negro', 'stocks' => [1 => 15]]
            ]
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['category_id']);
    }

    public function test_sales_pos_endpoints()
    {
        $admin = User::find(1);

        // Setup product variant with stock
        $response = $this->actingAs($admin)->postJson('/api/products', [
            'base_sku' => 'SHOE-SAMBA',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Samba Classic',
            'price' => 350.00,
            'variants' => [
                ['size' => '42', 'color' => 'Blanco', 'stocks' => [1 => 5]]
            ]
        ]);
        $response->assertStatus(201);

        // 1. Make POS Sale (should automatically resolve customer name if customer_id provided)
        $hashed = Hash::make('pass');
        DB::insert("INSERT INTO users (id, role_id, name, email, password, created_at, updated_at) VALUES (10, 3, 'Juan Perez', 'juan@perez.com', ?, ?, ?)", [$hashed, now(), now()]);

        $response = $this->actingAs($admin)->postJson('/api/sales', [
            'items' => [
                ['sku' => 'SHOE-SAMBA-42', 'quantity' => 2]
            ],
            'customer_id' => 10,
            'store_id' => 1
        ]);
        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        // Verify sale record customer_name resolved correctly from users table
        $sale = DB::select("SELECT customer_name FROM sales WHERE customer_id = 10 LIMIT 1")[0];
        $this->assertEquals('Juan Perez', $sale->customer_name);
        
        // Verify inventory stock reduced
        $variant = DB::select("SELECT id FROM product_variants WHERE sku = ? LIMIT 1", ['SHOE-SAMBA-42'])[0];
        $inventory = DB::select("SELECT stock FROM store_inventories WHERE store_id = 1 AND variant_id = ? LIMIT 1", [$variant->id])[0];
        $this->assertEquals(3, $inventory->stock); // 5 - 2 = 3

    }

    public function test_admin_sales_stats()
    {
        $admin = User::find(1);

        // Setup product variant with stock
        $this->actingAs($admin)->postJson('/api/products', [
            'base_sku' => 'SHOE-SAMBA',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Samba Classic',
            'price' => 350.00,
            'variants' => [
                ['size' => '42', 'color' => 'Blanco', 'stocks' => [1 => 5]]
            ]
        ]);

        // Make Admin sale
        $this->actingAs($admin)->postJson('/api/sales', [
            'items' => [
                ['sku' => 'SHOE-SAMBA-42', 'quantity' => 2]
            ],
            'store_id' => 1
        ]);

        // As Admin: stats should return 700 (350 * 2) and 1 sale
        $response = $this->actingAs($admin)->getJson('/api/sales/stats');
        $response->assertStatus(200)
                 ->assertJsonPath('stats.total_amount', 700)
                 ->assertJsonPath('stats.total_sales', 1);
    }

    public function test_seller_sales_visibility_and_stats()
    {
        $seller = User::where('role_id', 2)->first();

        // Setup product variant with stock via direct DB inserts (no auth switching)
        $timestamp = now();
        DB::insert("INSERT INTO products (id, base_sku, category_id, store_id, name, price, created_at, updated_at) VALUES (100, 'SHOE-SAMBA', 1, 1, 'Samba Classic', 350.00, ?, ?)", [$timestamp, $timestamp]);
        DB::insert("INSERT INTO product_variants (id, product_id, sku, size, created_at, updated_at) VALUES (100, 100, 'SHOE-SAMBA-42-BLANCO', '42', ?, ?)", [$timestamp, $timestamp]);
        DB::insert("INSERT INTO store_inventories (store_id, variant_id, stock, created_at, updated_at) VALUES (1, 100, 5, ?, ?)", [$timestamp, $timestamp]);

        // First assign seller to store (if not already assigned)
        $exists = DB::select("SELECT id FROM store_user WHERE store_id = 1 AND user_id = ?", [$seller->id]);
        if (empty($exists)) {
            DB::insert("INSERT INTO store_user (store_id, user_id, is_primary, assigned_by, created_at, updated_at) VALUES (1, ?, 1, 1, ?, ?)", [$seller->id, $timestamp, $timestamp]);
        }

        // As Seller: Should be able to list sales (which will be empty for seller)
        $response = $this->actingAs($seller)->getJson('/api/sales');
        $response->assertStatus(200)
                 ->assertJsonCount(0, 'data');

        // As Seller: stats should return 0
        $response = $this->actingAs($seller)->getJson('/api/sales/stats');
        $response->assertStatus(200)
                 ->assertJsonPath('stats.total_amount', 0)
                 ->assertJsonPath('stats.total_sales', 0);

        // Seller makes a sale
        $response = $this->actingAs($seller)->postJson('/api/sales', [
            'items' => [
                ['sku' => 'SHOE-SAMBA-42-BLANCO', 'quantity' => 1]
            ],
            'store_id' => 1
        ]);
        $response->assertStatus(201);

        // Seller gets stats: total 350, 1 sale
        $response = $this->actingAs($seller)->getJson('/api/sales/stats');
        $response->assertStatus(200)
                 ->assertJsonPath('stats.total_amount', 350)
                 ->assertJsonPath('stats.total_sales', 1);
    }

    public function test_best_sellers_and_public_stores_endpoints()
    {
        $admin = User::find(1);
        
        // Seed 10 products so the fallback has enough products to return 10 items
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($admin)->postJson('/api/products', [
                'base_sku' => "PROD-$i",
                'category_id' => 1,
                'store_id' => 1,
                'name' => "Producto $i",
                'price' => 10.00 + $i,
                'variants' => [
                    ['size' => 'M', 'color' => 'Negro', 'stocks' => [1 => 5]]
                ]
            ]);
        }

        // 1. Get best sellers
        $response = $this->getJson('/api/products/best-sellers');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(10, 'data'); // Fallback should ensure exactly 10 products

        // 2. Get public stores list
        $response = $this->getJson('/api/stores/public');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['success', 'stores' => [['id', 'name', 'address', 'phone', 'latitude', 'longitude']]]);
    }

    public function test_banners_active_list_public()
    {
        // 1. Create a banner via raw SQL insertion
        DB::insert("INSERT INTO banners (image_url, is_active, sort_order, created_at, updated_at) VALUES ('http://example.com/banner1.png', 1, 10, ?, ?)", [now(), now()]);
        DB::insert("INSERT INTO banners (image_url, is_active, sort_order, created_at, updated_at) VALUES ('http://example.com/banner2.png', 0, 5, ?, ?)", [now(), now()]);

        // 2. Fetch active banners (public route)
        $response = $this->getJson('/api/banners/active');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(1, 'banners')
                 ->assertJsonPath('banners.0.image_url', 'http://example.com/banner1.png');
    }

    public function test_banners_crud_requires_authentication()
    {
        // Try to access CRUD as guest (should fail)
        $response = $this->getJson('/api/banners');
        $response->assertStatus(401);

        $response = $this->postJson('/api/banners', [
            'is_active' => 1,
            'sort_order' => 1
        ]);
        $response->assertStatus(401);
    }

    public function test_banners_crud_requires_admin_role()
    {
        $seller = User::find(2);

        // Try to access CRUD as seller (non-admin, should fail)
        $response = $this->actingAs($seller)->getJson('/api/banners');
        $response->assertStatus(403);
    }

    public function test_banners_crud_as_admin_succeeds()
    {
        $admin = User::find(1);

        DB::insert("INSERT INTO banners (image_url, is_active, sort_order, created_at, updated_at) VALUES ('http://example.com/banner1.png', 1, 10, ?, ?)", [now(), now()]);
        DB::insert("INSERT INTO banners (image_url, is_active, sort_order, created_at, updated_at) VALUES ('http://example.com/banner2.png', 0, 5, ?, ?)", [now(), now()]);

        // 1. Access CRUD list
        $response = $this->actingAs($admin)->getJson('/api/banners');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(2, 'banners');

        // 2. Toggle banner status
        $banner = DB::select("SELECT id FROM banners WHERE image_url = 'http://example.com/banner2.png'")[0];
        $response = $this->actingAs($admin)->patchJson("/api/banners/{$banner->id}/toggle");
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('banner.is_active', 1);

        // 3. Reorder banners
        $banner1 = DB::select("SELECT id FROM banners WHERE image_url = 'http://example.com/banner1.png'")[0];
        $response = $this->actingAs($admin)->postJson('/api/banners/reorder', [
            'ids' => [$banner->id, $banner1->id]
        ]);
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // Verify the reorder changes in database
        $ordered = DB::select("SELECT id FROM banners ORDER BY sort_order ASC");
        $this->assertEquals($banner->id, $ordered[0]->id);
        $this->assertEquals($banner1->id, $ordered[1]->id);

        // Validation test: Negative sort_order
        $response = $this->actingAs($admin)->postJson("/api/banners/{$banner->id}", [
            'sort_order' => -5
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['sort_order']);

        // Validation test: Duplicate sort_order (banner1 has sort_order = 1. Try updating banner to 1)
        $response = $this->actingAs($admin)->postJson("/api/banners/{$banner->id}", [
            'sort_order' => 1
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['sort_order']);

        // 4. Delete banner
        $response = $this->actingAs($admin)->deleteJson("/api/banners/{$banner1->id}");
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $count = DB::select("SELECT COUNT(*) as count FROM banners")[0]->count;
        $this->assertEquals(1, $count);
    }

    public function test_whatsapp_numbers_endpoints()
    {
        $admin = User::find(1);

        // 1. Create a WhatsApp number
        $response = $this->actingAs($admin)->postJson('/api/settings/whatsapp-numbers', [
            'alias' => 'Soporte Ventas',
            'phone' => '+51999888777',
            'is_active' => true,
            'display_order' => 1
        ]);
        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        // 2. Try registering duplicate number (should fail validation)
        $response = $this->actingAs($admin)->postJson('/api/settings/whatsapp-numbers', [
            'alias' => 'Soporte Clientes',
            'phone' => '+51999888777',
            'is_active' => true,
            'display_order' => 2
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['phone']);

        // 3. Get active whatsapp numbers (Public route)
        $response = $this->getJson('/api/settings/whatsapp-numbers');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['success', 'data' => [['id', 'alias', 'phone', 'is_active', 'display_order']]]);

        // 4. Update WhatsApp number
        $id = DB::select("SELECT id FROM whatsapp_numbers LIMIT 1")[0]->id;
        $response = $this->actingAs($admin)->putJson("/api/settings/whatsapp-numbers/{$id}", [
            'alias' => 'Soporte Modificado',
            'phone' => '+51999888777',
            'is_active' => false,
            'display_order' => 5
        ]);
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.is_active', false);
    }

    public function test_seller_commission_configuration()
    {
        $admin = User::find(1);
        $seller = User::find(2);

        // 1. Get sellers commission settings list
        $response = $this->actingAs($admin)->getJson('/api/users/sellers/commissions');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // 2. Set seller commission
        $response = $this->actingAs($admin)->putJson('/api/users/sellers/2/commissions', [
            'commission_percentage' => 7.50
        ]);
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // Verify updated in database
        $this->assertEquals(7.50, DB::select("SELECT commission_percentage FROM users WHERE id = 2")[0]->commission_percentage);
    }

    public function test_seller_commission_sales_calculation()
    {
        $seller = User::find(2);

        // Set commission to 5.00%
        DB::update("UPDATE users SET commission_percentage = 5.00 WHERE id = 2");

        // Seed product using repository directly
        $productRepo = app(\App\Repositories\ProductRepository::class);
        
        // Ensure user is authenticated to get auth()->id() inside create() method
        $this->actingAs($seller);
        
        $productRepo->create([
            'base_sku' => 'COMM-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto Comisiones',
            'price' => 200.00,
            'purchase_price' => 120.00,
            'variants' => [
                ['size' => 'M', 'color' => 'Rojo', 'stocks' => [1 => 10]]
            ]
        ], 1);

        $variant = DB::select("SELECT sku FROM product_variants WHERE product_id = (SELECT id FROM products WHERE base_sku = 'COMM-TEST' LIMIT 1) LIMIT 1")[0];

        // Process POS sale as seller
        $response = $this->postJson('/api/sales', [
            'items' => [
                ['sku' => $variant->sku, 'quantity' => 1]
            ]
        ]);
        $response->assertStatus(201);

        $saleId = $response->json('sale_id');

        // Verify sale was saved with 5% commission and amount 10.00
        $sale = DB::select("SELECT commission_percentage, commission_amount, total FROM sales WHERE id = ? LIMIT 1", [$saleId])[0];
        $this->assertEquals(5.00, $sale->commission_percentage);
        $this->assertEquals(10.00, $sale->commission_amount);
    }

    public function test_product_soft_delete_and_restore()
    {
        $admin = User::find(1);

        // Seed product
        $this->actingAs($admin)->postJson('/api/products', [
            'base_sku' => 'SOFT-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto Soft Delete',
            'price' => 150.00,
            'purchase_price' => 90.00,
            'variants' => [
                ['size' => 'S', 'color' => 'Azul', 'stocks' => [1 => 5]]
            ]
        ]);

        // Delete product
        $response = $this->actingAs($admin)->deleteJson('/api/products/SOFT-TEST');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // Check is marked as deleted in database
        $deletedAt = DB::select("SELECT deleted_at FROM products WHERE base_sku = 'SOFT-TEST' LIMIT 1")[0]->deleted_at;
        $this->assertNotNull($deletedAt);

        // Restore product
        $response = $this->actingAs($admin)->postJson('/api/products/SOFT-TEST/restore');
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // Check is active again
        $deletedAt = DB::select("SELECT deleted_at FROM products WHERE base_sku = 'SOFT-TEST' LIMIT 1")[0]->deleted_at;
        $this->assertNull($deletedAt);
    }

    public function test_sale_update_adjusts_inventory_and_totals()
    {
        $admin = User::find(1);
        $seller = User::find(2);

        // Set commission to 10%
        DB::update("UPDATE users SET commission_percentage = 10.00 WHERE id = 2");

        // Seed product
        $productRepo = app(\App\Repositories\ProductRepository::class);
        $this->actingAs($seller);
        $productRepo->create([
            'base_sku' => 'EDIT-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto Editable',
            'price' => 100.00,
            'purchase_price' => 60.00,
            'variants' => [
                ['size' => 'M', 'color' => 'Gris', 'stocks' => [1 => 10]]
            ]
        ], 1);

        $variant = DB::select("SELECT id, sku FROM product_variants WHERE sku = 'EDIT-TEST-M' LIMIT 1")[0];

        // 1. Process initial sale: 3 units (stock: 10 -> 7)
        $response = $this->postJson('/api/sales', [
            'items' => [
                ['sku' => $variant->sku, 'quantity' => 3]
            ],
            'customer_name' => 'Cliente Inicial',
            'store_id' => 1
        ]);
        $response->assertStatus(201);
        $saleId = $response->json('sale_id');

        // Check stock is 7
        $stock = DB::select("SELECT stock FROM store_inventories WHERE store_id = 1 AND variant_id = ?", [$variant->id])[0]->stock;
        $this->assertEquals(7, $stock);

        // 2. Edit sale: change quantity to 2, price to 90.00 (stock: 7 -> 8, total: 300 -> 180, commission: 30 -> 18)
        $this->flushSession();
        $response = $this->actingAs($admin)->putJson("/api/sales/{$saleId}", [
            'items' => [
                ['sku' => $variant->sku, 'quantity' => 2, 'price' => 90.00]
            ],
            'status' => 'EXCHANGED',
            'customer_name' => 'Cliente Modificado'
        ]);
        $response->assertStatus(200);

        // Check stock is 8
        $stock = DB::select("SELECT stock FROM store_inventories WHERE store_id = 1 AND variant_id = ?", [$variant->id])[0]->stock;
        $this->assertEquals(8, $stock);

        // Verify sale record updated
        $sale = DB::select("SELECT total, status, customer_name, commission_amount, updated_by FROM sales WHERE id = ? LIMIT 1", [$saleId])[0];
        $this->assertEquals(180.00, $sale->total);
        $this->assertEquals('EXCHANGED', $sale->status);
        $this->assertEquals('Cliente Modificado', $sale->customer_name);
        $this->assertEquals(18.00, $sale->commission_amount);
        $this->assertEquals(1, $sale->updated_by); // Admin modified it
    }

    public function test_cancelled_and_refunded_sales_excluded_from_stats()
    {
        $admin = User::find(1);

        // Create one COMPLETED sale (seeded product TSHIRT-NIKE-M from migrations/other tests)
        $variant = DB::select("SELECT sku FROM product_variants LIMIT 1")[0]->sku;

        // Reset stock to prevent insufficient stock error
        DB::update("UPDATE store_inventories SET stock = 100");

        // Sale 1: Completed
        $response = $this->actingAs($admin)->postJson('/api/sales', [
            'items' => [
                ['sku' => $variant, 'quantity' => 1, 'price' => 100.00]
            ],
            'store_id' => 1
        ]);
        $response->assertStatus(201);

        // Sale 2: Completed, then we cancel it
        $response = $this->actingAs($admin)->postJson('/api/sales', [
            'items' => [
                ['sku' => $variant, 'quantity' => 1, 'price' => 200.00]
            ],
            'store_id' => 1
        ]);
        $response->assertStatus(201);
        $saleId = $response->json('sale_id');

        // Cancel it
        $response = $this->actingAs($admin)->putJson("/api/sales/{$saleId}", [
            'items' => [
                ['sku' => $variant, 'quantity' => 1, 'price' => 200.00]
            ],
            'status' => 'CANCELLED'
        ]);
        $response->assertStatus(200);

        // Fetch stats
        $response = $this->actingAs($admin)->getJson('/api/sales/stats');
        $response->assertStatus(200)
                 ->assertJsonPath('stats.total_amount', 100)
                 ->assertJsonPath('stats.total_sales', 1);
    }

}
