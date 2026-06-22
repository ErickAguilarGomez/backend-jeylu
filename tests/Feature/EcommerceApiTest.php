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
                 ->assertJsonStructure(['success', 'user' => ['id', 'name', 'email', 'role_id']]);
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
            'address' => 'Av. Alfredo Mendiola 1234'
        ]);
        $response->assertStatus(201);

        // 3. Update Store with uniqueness validation
        $response = $this->actingAs($admin)->putJson('/api/stores/1', [
            'name' => 'Tienda Sur',
            'address' => 'Av. Tomas Marsano 4321'
        ]);
        $response->assertStatus(200);

        // Try to update sur store to north store name (duplicate)
        $response = $this->actingAs($admin)->putJson('/api/stores/1', [
            'name' => 'Tienda Norte',
            'address' => 'Av. Tomas Marsano 4321'
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
                ['size' => 'M', 'color' => 'Negro', 'stock' => 10],
                ['size' => 'L', 'color' => 'Negro', 'stock' => 5]
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
                ['size' => 'M', 'color' => 'Negro', 'stock' => 15]
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
                ['size' => '42', 'color' => 'Blanco', 'stock' => 5]
            ]
        ]);
        $response->assertStatus(201);

        // 1. Make POS Sale (should automatically resolve customer name if customer_id provided)
        $hashed = Hash::make('pass');
        DB::insert("INSERT INTO users (id, role_id, name, email, password, created_at, updated_at) VALUES (10, 3, 'Juan Perez', 'juan@perez.com', ?, ?, ?)", [$hashed, now(), now()]);

        $response = $this->actingAs($admin)->postJson('/api/sales', [
            'items' => [
                ['sku' => 'SHOE-SAMBA-42-BLANCO', 'quantity' => 2]
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
        $variant = DB::select("SELECT id FROM product_variants WHERE sku = ? LIMIT 1", ['SHOE-SAMBA-42-BLANCO'])[0];
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
                ['size' => '42', 'color' => 'Blanco', 'stock' => 5]
            ]
        ]);

        // Make Admin sale
        $this->actingAs($admin)->postJson('/api/sales', [
            'items' => [
                ['sku' => 'SHOE-SAMBA-42-BLANCO', 'quantity' => 2]
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
        DB::insert("INSERT INTO products (id, base_sku, category_id, store_id, name, price, created_at, updated_at) VALUES (100, 'SHOE-SAMBA', 1, 1, 'Samba Classic', 350.00, now(), now())");
        DB::insert("INSERT INTO product_variants (id, product_id, sku, size, created_at, updated_at) VALUES (100, 100, 'SHOE-SAMBA-42-BLANCO', '42', now(), now())");
        DB::insert("INSERT INTO store_inventories (store_id, variant_id, stock, created_at, updated_at) VALUES (1, 100, 5, now(), now())");

        // First assign seller to store (if not already assigned)
        $exists = DB::select("SELECT id FROM store_user WHERE store_id = 1 AND user_id = ?", [$seller->id]);
        if (empty($exists)) {
            DB::insert("INSERT INTO store_user (store_id, user_id, is_primary, assigned_by, created_at, updated_at) VALUES (1, ?, 1, 1, now(), now())", [$seller->id]);
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
                    ['size' => 'M', 'color' => 'Negro', 'stock' => 5]
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

}
