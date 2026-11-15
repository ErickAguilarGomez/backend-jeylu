<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

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
        DB::insert(
            'INSERT INTO payment_methods (id, name, is_active, created_at, updated_at) VALUES (1, ?, 1, ?, ?)',
            ['Efectivo de prueba', now(), now()]
        );
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

    public function test_login_rate_limits_repeated_failed_credentials()
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'admin@jeilu.com',
                'password' => 'wrongpassword',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'admin@jeilu.com',
            'password' => 'wrongpassword',
        ])->assertTooManyRequests();

        $this->postJson('/api/auth/login', [
            'email' => 'another-user@jeilu.com',
            'password' => 'wrongpassword',
        ])->assertUnauthorized();
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

    public function test_product_edit_by_id_supports_base_skus_with_slashes()
    {
        $admin = User::find(1);
        $this->actingAs($admin)->postJson('/api/products', [
            'base_sku' => '1002/NA',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con SKU compuesto',
            'price' => 89.90,
            'variants' => [
                ['size' => 'M', 'color' => 'Negro', 'stocks' => [1 => 10]],
            ],
        ])->assertCreated();

        $productId = (int) DB::select(
            'SELECT id FROM products WHERE base_sku = ? LIMIT 1',
            ['1002/NA']
        )[0]->id;

        $this->getJson("/api/products/by-id/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.id', $productId)
            ->assertJsonPath('data.sku', '1002/NA');

        $this->actingAs($admin)->postJson("/api/products/by-id/{$productId}", [
            'base_sku' => '1002/NA',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con SKU compuesto editado',
            'price' => 99.90,
            'variants' => [
                ['size' => 'M', 'color' => 'Negro', 'stocks' => [1 => 12]],
            ],
        ])->assertOk()
            ->assertJsonPath('data.sku', '1002/NA')
            ->assertJsonPath('data.name', 'Producto con SKU compuesto editado');

        $this->actingAs($admin)->postJson('/api/products', [
            'base_sku' => 'SKU-COLLISION',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto padre de variante',
            'price' => 79.90,
            'variants' => [
                ['size' => 'M', 'color' => 'Negro', 'stocks' => [1 => 4]],
            ],
        ])->assertCreated();

        DB::insert(
            'INSERT INTO products (base_sku, category_id, store_id, name, price, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['SKU-COLLISION-M', 1, 1, 'Producto con SKU igual a una variante ajena', 89.90, now(), now()]
        );
        $collisionProductId = (int) DB::getPdo()->lastInsertId();
        DB::insert(
            'INSERT INTO product_variants (product_id, sku, size, color, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$collisionProductId, 'SKU-COLLISION-M-L', 'L', 'Azul', now(), now()]
        );

        $this->getJson("/api/products/by-id/{$collisionProductId}")
            ->assertOk()
            ->assertJsonPath('data.id', $collisionProductId)
            ->assertJsonPath('data.sku', 'SKU-COLLISION-M')
            ->assertJsonPath('data.name', 'Producto con SKU igual a una variante ajena');
    }

    public function test_product_create_accepts_multiple_images_and_variants()
    {
        $this->actingAs(User::find(1));
        Storage::fake('public');
        DB::update('UPDATE cloudinary_configs SET is_active = 0');

        $response = $this->post('/api/products', [
            'base_sku' => 'MOBILE-CREATE-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto creado desde móvil',
            'price' => 89.90,
            'purchase_price' => 40.00,
            'variants' => [
                ['size' => 'S', 'color' => 'Negro', 'stocks' => [1 => 4]],
                ['size' => 'M', 'color' => 'Azul', 'stocks' => [1 => 6]],
            ],
            'images' => array_merge(
                array_map(
                    static fn (int $index) => UploadedFile::fake()->image("producto-{$index}.jpg", 64, 64),
                    range(1, 19)
                ),
                [UploadedFile::fake()->image('producto-webp.webp', 64, 64)]
            ),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('success', true);

        $product = DB::select(
            'SELECT id FROM products WHERE base_sku = ? LIMIT 1',
            ['MOBILE-CREATE-TEST']
        )[0];

        $this->assertSame(20, (int) DB::select(
            'SELECT COUNT(*) AS total FROM product_images WHERE product_id = ?',
            [$product->id]
        )[0]->total);
        $this->assertSame(1, (int) DB::select(
            'SELECT COUNT(*) AS total FROM product_images WHERE product_id = ? AND is_primary = 1',
            [$product->id]
        )[0]->total);
        $this->assertSame(2, (int) DB::select(
            'SELECT COUNT(*) AS total FROM product_variants WHERE product_id = ?',
            [$product->id]
        )[0]->total);
        $this->assertSame(2, (int) DB::select(
            'SELECT COUNT(*) AS total FROM store_inventories si
             INNER JOIN product_variants pv ON pv.id = si.variant_id
             WHERE pv.product_id = ?',
            [$product->id]
        )[0]->total);
        $this->assertCount(20, Storage::disk('public')->allFiles('ecommerce_products'));

        $publicImageUrlPrefix = Storage::disk('public')->url('ecommerce_products') . '/';
        foreach (DB::select('SELECT image_url FROM product_images WHERE product_id = ?', [$product->id]) as $image) {
            $this->assertStringStartsWith($publicImageUrlPrefix, $image->image_url);
        }
    }

    public function test_product_update_accepts_multiple_images_and_swaps_variant_skus()
    {
        $admin = User::find(1);
        $this->actingAs($admin);
        Storage::fake('public');
        DB::update('UPDATE cloudinary_configs SET is_active = 0');

        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'MOBILE-UPDATE-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto para edición móvil',
            'price' => 100.00,
            'purchase_price' => 50.00,
            'variants' => [
                ['size' => 'S', 'color' => 'Negro', 'stocks' => [1 => 2]],
                ['size' => 'M', 'color' => 'Azul', 'stocks' => [1 => 3]],
            ],
        ], 1);

        $product = DB::select("SELECT id FROM products WHERE base_sku = 'MOBILE-UPDATE-TEST' LIMIT 1")[0];
        $variants = DB::select(
            'SELECT id, size FROM product_variants WHERE product_id = ? ORDER BY size',
            [$product->id]
        );
        $variantIds = [];
        foreach ($variants as $variant) {
            $variantIds[$variant->size] = (int) $variant->id;
        }

        $response = $this->post('/api/products/MOBILE-UPDATE-TEST', [
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto actualizado desde móvil',
            'price' => 120.00,
            'purchase_price' => 55.00,
            'variants' => [
                ['id' => $variantIds['M'], 'size' => 'S', 'color' => 'Verde', 'stocks' => [1 => 5]],
                ['id' => $variantIds['S'], 'size' => 'M', 'color' => 'Rojo', 'stocks' => [1 => 7]],
            ],
            'images' => array_map(
                static fn (int $index) => UploadedFile::fake()->image("edicion-{$index}.jpg", 64, 64),
                range(1, 20)
            ),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('success', true);

        $updatedVariants = DB::select(
            'SELECT id, sku, size, color FROM product_variants WHERE product_id = ? ORDER BY size',
            [$product->id]
        );
        $this->assertCount(2, $updatedVariants);
        $updatedBySize = [];
        foreach ($updatedVariants as $variant) {
            $updatedBySize[$variant->size] = $variant;
        }
        $this->assertSame('MOBILE-UPDATE-TEST-S', $updatedBySize['S']->sku);
        $this->assertSame('MOBILE-UPDATE-TEST-M', $updatedBySize['M']->sku);
        $this->assertSame('Verde', $updatedBySize['S']->color);
        $this->assertSame('Rojo', $updatedBySize['M']->color);

        $this->assertSame(20, (int) DB::select(
            'SELECT COUNT(*) AS total FROM product_images WHERE product_id = ?',
            [$product->id]
        )[0]->total);
        $this->assertSame(1, (int) DB::select(
            'SELECT COUNT(*) AS total FROM product_images WHERE product_id = ? AND is_primary = 1',
            [$product->id]
        )[0]->total);
        $this->assertSame(5, (int) DB::select(
            'SELECT stock FROM store_inventories WHERE store_id = 1 AND variant_id = ?',
            [$variantIds['M']]
        )[0]->stock);
    }

    public function test_failed_product_create_rolls_back_new_purchase_order_and_cleans_its_file()
    {
        $this->actingAs(User::find(1));
        $purchaseOrderFileUrl = 'https://res.cloudinary.com/example/image/upload/purchase_orders/new-order.pdf';

        $cloudinary = \Mockery::mock(\App\Services\CloudinaryService::class);
        $cloudinary->shouldReceive('upload')->once()->andReturn($purchaseOrderFileUrl);
        $cloudinary->shouldReceive('delete')->once()->with($purchaseOrderFileUrl)->andReturn(true);
        $this->app->instance(\App\Services\CloudinaryService::class, $cloudinary);

        $productRepo = \Mockery::mock(\App\Repositories\ProductRepository::class)->makePartial();
        $productRepo->shouldReceive('findBySku')->once()->with('PO-ROLLBACK-CREATE-TEST')->andReturn(null);
        $productRepo->shouldReceive('create')->once()->andThrow(new \Illuminate\Database\QueryException(
            'mysql',
            'insert into products',
            [],
            new \PDOException('Simulated product insert failure', 1)
        ));
        $this->app->instance(\App\Repositories\ProductRepository::class, $productRepo);

        $response = $this->post('/api/products', [
            'base_sku' => 'PO-ROLLBACK-CREATE-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto que no debe crearse',
            'price' => 89.90,
            'purchase_price' => 40.00,
            'purchase_order_option' => 'new',
            'purchase_order_file' => UploadedFile::fake()->create('orden.pdf', 10, 'application/pdf'),
            'variants' => [[
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 4],
            ]],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $this->assertSame(0, (int) DB::select('SELECT COUNT(*) AS total FROM purchase_orders')[0]->total);
        $this->assertSame(0, (int) DB::select(
            'SELECT COUNT(*) AS total FROM products WHERE base_sku = ?',
            ['PO-ROLLBACK-CREATE-TEST']
        )[0]->total);
    }

    public function test_failed_product_update_rolls_back_purchase_order_file_replacement()
    {
        $this->actingAs(User::find(1));
        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'PO-ROLLBACK-UPDATE-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto antes de editar',
            'price' => 89.90,
            'purchase_price' => 40.00,
            'variants' => [[
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 4],
            ]],
        ], 1);

        $product = DB::select(
            'SELECT id FROM products WHERE base_sku = ?',
            ['PO-ROLLBACK-UPDATE-TEST']
        )[0];
        $variant = DB::select(
            'SELECT id FROM product_variants WHERE product_id = ?',
            [$product->id]
        )[0];
        $oldFileUrl = 'https://res.cloudinary.com/example/image/upload/purchase_orders/old-order.pdf';
        $newFileUrl = 'https://res.cloudinary.com/example/image/upload/purchase_orders/new-order.pdf';
        DB::insert(
            'INSERT INTO purchase_orders (order_number, file_url, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['ORDER-OLD', $oldFileUrl, now(), now()]
        );
        $purchaseOrderId = (int) DB::getPdo()->lastInsertId();
        DB::update('UPDATE products SET purchase_order_id = ? WHERE id = ?', [$purchaseOrderId, $product->id]);

        $cloudinary = \Mockery::mock(\App\Services\CloudinaryService::class);
        $cloudinary->shouldReceive('upload')->once()->andReturn($newFileUrl);
        $cloudinary->shouldReceive('delete')->once()->with($newFileUrl)->andReturn(true);
        $this->app->instance(\App\Services\CloudinaryService::class, $cloudinary);

        $productRepoMock = \Mockery::mock($productRepo)->makePartial();
        $productRepoMock->shouldReceive('update')->once()->andThrow(new \Illuminate\Database\QueryException(
            'mysql',
            'update products',
            [],
            new \PDOException('Simulated product update failure', 1)
        ));
        $this->app->instance(\App\Repositories\ProductRepository::class, $productRepoMock);

        $response = $this->post('/api/products/PO-ROLLBACK-UPDATE-TEST', [
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto editado que no debe guardarse',
            'price' => 99.90,
            'purchase_price' => 45.00,
            'purchase_order_option' => 'existing',
            'purchase_order_id' => $purchaseOrderId,
            'purchase_order_file' => UploadedFile::fake()->create('orden-nueva.pdf', 10, 'application/pdf'),
            'variants' => [[
                'id' => (int) $variant->id,
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 4],
            ]],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $this->assertSame($oldFileUrl, DB::select(
            'SELECT file_url FROM purchase_orders WHERE id = ?',
            [$purchaseOrderId]
        )[0]->file_url);
        $this->assertSame('Producto antes de editar', DB::select(
            'SELECT name FROM products WHERE id = ?',
            [$product->id]
        )[0]->name);
    }

    public function test_product_update_removes_inventory_for_a_removed_variant()
    {
        $this->actingAs(User::find(1));
        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'MOBILE-REMOVE-VARIANT-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con variante por retirar',
            'price' => 80.00,
            'purchase_price' => 35.00,
            'variants' => [
                ['size' => 'S', 'color' => 'Negro', 'stocks' => [1 => 3]],
                ['size' => 'M', 'color' => 'Azul', 'stocks' => [1 => 4]],
            ],
        ], 1);

        $product = DB::select(
            'SELECT id FROM products WHERE base_sku = ? LIMIT 1',
            ['MOBILE-REMOVE-VARIANT-TEST']
        )[0];
        $variants = DB::select(
            'SELECT id, size FROM product_variants WHERE product_id = ?',
            [$product->id]
        );
        $variantIds = [];
        foreach ($variants as $variant) {
            $variantIds[$variant->size] = (int) $variant->id;
        }

        $response = $this->post('/api/products/MOBILE-REMOVE-VARIANT-TEST', [
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto actualizado sin la variante M',
            'price' => 85.00,
            'purchase_price' => 36.00,
            'variants' => [[
                'id' => $variantIds['S'],
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 3],
            ]],
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, (int) DB::select(
            'SELECT COUNT(*) AS total FROM product_variants WHERE product_id = ?',
            [$product->id]
        )[0]->total);
        $this->assertSame(0, (int) DB::select(
            'SELECT COUNT(*) AS total FROM store_inventories WHERE variant_id = ?',
            [$variantIds['M']]
        )[0]->total);
    }

    public function test_deleting_primary_local_image_promotes_the_next_image_without_cloudinary()
    {
        $this->actingAs(User::find(1));
        Storage::fake('public');
        DB::update('UPDATE cloudinary_configs SET is_active = 0');

        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'LOCAL-IMAGE-DELETE-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con imágenes locales',
            'price' => 80.00,
            'purchase_price' => 35.00,
            'variants' => [[
                'size' => 'U',
                'color' => null,
                'stocks' => [1 => 2],
            ]],
        ], 1);

        $productId = (int) DB::select(
            'SELECT id FROM products WHERE base_sku = ? LIMIT 1',
            ['LOCAL-IMAGE-DELETE-TEST']
        )[0]->id;
        $primaryPath = 'ecommerce_products/primary.webp';
        $nextPath = 'ecommerce_products/next.webp';
        Storage::disk('public')->put($primaryPath, 'primary image');
        Storage::disk('public')->put($nextPath, 'next image');
        $primaryUrl = Storage::disk('public')->url($primaryPath);
        $nextUrl = Storage::disk('public')->url($nextPath);

        DB::insert(
            'INSERT INTO product_images (product_id, image_url, is_primary, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?), (?, ?, 0, ?, ?)',
            [$productId, $primaryUrl, now(), now(), $productId, $nextUrl, now(), now()]
        );

        $response = $this->deleteJson('/api/products/images', ['image_url' => $primaryUrl]);

        $response->assertOk()->assertJsonPath('success', true);
        Storage::disk('public')->assertMissing($primaryPath);
        Storage::disk('public')->assertExists($nextPath);
        $remainingImages = DB::select(
            'SELECT image_url, is_primary FROM product_images WHERE product_id = ?',
            [$productId]
        );
        $this->assertCount(1, $remainingImages);
        $this->assertSame($nextUrl, $remainingImages[0]->image_url);
        $this->assertSame(1, (int) $remainingImages[0]->is_primary);
    }

    public function test_product_image_database_reference_is_removed_before_storage_cleanup()
    {
        $this->actingAs(User::find(1));
        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'IMAGE-CLEANUP-ORDER-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto para verificar eliminación segura',
            'price' => 80.00,
            'purchase_price' => 35.00,
            'variants' => [[
                'size' => 'U',
                'color' => null,
                'stocks' => [1 => 2],
            ]],
        ], 1);

        $productId = (int) DB::select(
            'SELECT id FROM products WHERE base_sku = ? LIMIT 1',
            ['IMAGE-CLEANUP-ORDER-TEST']
        )[0]->id;
        $imageUrl = 'https://res.cloudinary.com/example/image/upload/product-image.jpg';
        DB::insert(
            'INSERT INTO product_images (product_id, image_url, is_primary, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?)',
            [$productId, $imageUrl, now(), now()]
        );

        $this->mock(\App\Services\CloudinaryService::class, function ($mock) use ($imageUrl, $productId) {
            $mock->shouldReceive('delete')
                ->once()
                ->with($imageUrl)
                ->andReturnUsing(function () use ($imageUrl, $productId) {
                    $this->assertSame([], DB::select(
                        'SELECT id FROM product_images WHERE product_id = ? AND image_url = ?',
                        [$productId, $imageUrl]
                    ));

                    return false;
                });
        });

        $this->deleteJson('/api/products/images', ['image_url' => $imageUrl])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('storage_cleanup_pending', true);
    }

    public function test_product_update_rejects_duplicate_or_foreign_variant_ids_without_changes()
    {
        $this->actingAs(User::find(1));
        $productRepo = app(\App\Repositories\ProductRepository::class);

        foreach ([
            ['base_sku' => 'MOBILE-OWNER-TEST', 'size' => 'S'],
            ['base_sku' => 'MOBILE-OTHER-TEST', 'size' => 'M'],
        ] as $fixture) {
            $productRepo->create([
                'base_sku' => $fixture['base_sku'],
                'category_id' => 1,
                'store_id' => 1,
                'name' => 'Producto ' . $fixture['base_sku'],
                'price' => 80.00,
                'purchase_price' => 35.00,
                'variants' => [[
                    'size' => $fixture['size'],
                    'color' => 'Negro',
                    'stocks' => [1 => 3],
                ]],
            ], 1);
        }

        $products = DB::select(
            'SELECT p.id AS product_id, p.base_sku, pv.id AS variant_id
             FROM products p
             INNER JOIN product_variants pv ON pv.product_id = p.id
             WHERE p.base_sku IN (?, ?)',
            ['MOBILE-OWNER-TEST', 'MOBILE-OTHER-TEST']
        );
        $idsBySku = [];
        foreach ($products as $row) {
            $idsBySku[$row->base_sku] = (int) $row->variant_id;
        }

        $basePayload = [
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto sin cambios',
            'price' => 90.00,
            'purchase_price' => 40.00,
        ];

        $duplicateResponse = $this->post('/api/products/MOBILE-OWNER-TEST', $basePayload + [
            'variants' => [
                ['id' => $idsBySku['MOBILE-OWNER-TEST'], 'size' => 'S', 'color' => 'Negro', 'stocks' => [1 => 3]],
                ['id' => $idsBySku['MOBILE-OWNER-TEST'], 'size' => 'M', 'color' => 'Azul', 'stocks' => [1 => 2]],
            ],
        ], ['Accept' => 'application/json']);
        $duplicateResponse->assertUnprocessable()
            ->assertJsonPath('message', 'La solicitud contiene variantes duplicadas.');

        $foreignResponse = $this->post('/api/products/MOBILE-OWNER-TEST', $basePayload + [
            'variants' => [[
                'id' => $idsBySku['MOBILE-OTHER-TEST'],
                'size' => 'S',
                'color' => 'Rojo',
                'stocks' => [1 => 1],
            ]],
        ], ['Accept' => 'application/json']);
        $foreignResponse->assertUnprocessable()
            ->assertJsonPath('message', 'Una o más variantes no pertenecen a este producto. Recarga el producto e inténtalo de nuevo.');

        $this->assertSame(1, (int) DB::select(
            'SELECT COUNT(*) AS total FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             WHERE p.base_sku = ?',
            ['MOBILE-OWNER-TEST']
        )[0]->total);
        $this->assertSame('Producto MOBILE-OWNER-TEST', DB::select(
            'SELECT name FROM products WHERE base_sku = ?',
            ['MOBILE-OWNER-TEST']
        )[0]->name);
    }

    public function test_product_create_and_update_reject_variant_skus_longer_than_the_database_column()
    {
        $this->actingAs(User::find(1));
        $tooLongBaseSku = str_repeat('X', 100);
        $payload = [
            'base_sku' => $tooLongBaseSku,
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con SKU largo',
            'price' => 80.00,
            'purchase_price' => 35.00,
            'variants' => [[
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 3],
            ]],
        ];

        $createResponse = $this->post('/api/products', $payload, ['Accept' => 'application/json']);
        $createResponse->assertUnprocessable()
            ->assertJsonPath('message', 'La combinación del SKU base y la talla genera un SKU de variante demasiado largo. Reduce el SKU base o la talla.');
        $this->assertSame(0, (int) DB::select(
            'SELECT COUNT(*) AS total FROM products WHERE base_sku = ?',
            [$tooLongBaseSku]
        )[0]->total);

        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'SKU-LENGTH-EDIT-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto para conservar',
            'price' => 80.00,
            'purchase_price' => 35.00,
            'variants' => [[
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 3],
            ]],
        ], 1);
        $variant = DB::select(
            'SELECT id, sku FROM product_variants WHERE sku = ? LIMIT 1',
            ['SKU-LENGTH-EDIT-TEST-S']
        )[0];

        $updatePayload = $payload;
        $updatePayload['variants'][0]['id'] = (int) $variant->id;
        $updatePayload['name'] = 'Este cambio no debe aplicarse';
        $updateResponse = $this->post(
            '/api/products/SKU-LENGTH-EDIT-TEST',
            $updatePayload,
            ['Accept' => 'application/json']
        );
        $updateResponse->assertUnprocessable()
            ->assertJsonPath('message', 'La combinación del SKU base y la talla genera un SKU de variante demasiado largo. Reduce el SKU base o la talla.');

        $this->assertSame('Producto para conservar', DB::select(
            'SELECT name FROM products WHERE base_sku = ?',
            ['SKU-LENGTH-EDIT-TEST']
        )[0]->name);
        $this->assertSame($variant->sku, DB::select(
            'SELECT sku FROM product_variants WHERE id = ?',
            [$variant->id]
        )[0]->sku);
    }

    public function test_product_update_cannot_remove_variant_with_sales()
    {
        $this->actingAs(User::find(1));
        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'MOBILE-SOLD-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con venta',
            'price' => 80.00,
            'purchase_price' => 35.00,
            'variants' => [[
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 3],
            ]],
        ], 1);

        $saleResponse = $this->postJson('/api/sales', [
            'items' => [['sku' => 'MOBILE-SOLD-TEST-S', 'quantity' => 1]],
            'store_id' => 1,
            'payment_method_id' => 1,
        ]);
        $saleResponse->assertCreated();

        $response = $this->post('/api/products/MOBILE-SOLD-TEST', [
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con venta actualizado',
            'price' => 90.00,
            'purchase_price' => 40.00,
            'variants' => [[
                'size' => 'M',
                'color' => 'Azul',
                'stocks' => [1 => 2],
            ]],
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'No puedes eliminar variantes que ya tienen ventas registradas. Conserva esas variantes y pon su stock en 0.');
        $this->assertSame('MOBILE-SOLD-TEST-S', DB::select(
            'SELECT sku FROM product_variants WHERE sku = ?',
            ['MOBILE-SOLD-TEST-S']
        )[0]->sku);
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
            'store_id' => 1,
            'payment_method_id' => 1,
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

    public function test_public_checkout_rejects_non_positive_quantities_without_changing_stock()
    {
        $admin = User::find(1);

        $this->actingAs($admin)->postJson('/api/products', [
            'base_sku' => 'CHECKOUT-QUANTITY-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto para validar cantidad',
            'price' => 50.00,
            'variants' => [[
                'size' => 'S',
                'color' => 'Negro',
                'stocks' => [1 => 5],
            ]],
        ])->assertCreated();

        $variant = DB::select(
            'SELECT id FROM product_variants WHERE sku = ?',
            ['CHECKOUT-QUANTITY-TEST-S']
        )[0];

        foreach ([-2, 0, 'no-numérico'] as $quantity) {
            $response = $this->postJson('/api/sales/checkout', [
                'items' => [[
                    'sku' => 'CHECKOUT-QUANTITY-TEST-S',
                    'quantity' => $quantity,
                ]],
                'customer_name' => 'Cliente de prueba',
                'customer_phone' => '999999999',
                'delivery_type' => 'pickup',
                'store_id' => 1,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['items.0.quantity']);
        }

        $this->assertSame(5, (int) DB::select(
            'SELECT stock FROM store_inventories WHERE store_id = 1 AND variant_id = ?',
            [$variant->id]
        )[0]->stock);
        $this->assertSame(0, (int) DB::select('SELECT COUNT(*) AS total FROM sales')[0]->total);
    }

    public function test_public_pickup_checkout_does_not_silently_switch_the_selected_store()
    {
        DB::insert(
            'INSERT INTO stores (id, name, address, phone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [2, 'Sucursal Norte de prueba', 'Av. Norte 200', null, now(), now()]
        );

        $productRepo = app(\App\Repositories\ProductRepository::class);
        $productRepo->create([
            'base_sku' => 'CHECKOUT-PICKUP-STORE-TEST',
            'category_id' => 1,
            'store_id' => 1,
            'name' => 'Producto con inventario por sucursal',
            'price' => 75.00,
            'purchase_price' => 30.00,
            'variants' => [[
                'size' => 'M',
                'color' => 'Azul',
                'stocks' => [1 => 5, 2 => 5],
            ]],
        ], 1);

        $variant = DB::select(
            'SELECT id FROM product_variants WHERE sku = ?',
            ['CHECKOUT-PICKUP-STORE-TEST-M']
        )[0];

        $payload = [
            'items' => [[
                'sku' => 'CHECKOUT-PICKUP-STORE-TEST-M',
                'quantity' => 1,
            ]],
            'customer_name' => 'Cliente de prueba',
            'customer_phone' => '999999999',
            'delivery_type' => 'pickup',
            'store_id' => 2,
        ];

        $this->postJson('/api/sales/checkout', $payload)->assertCreated();
        $this->assertSame(2, (int) DB::select('SELECT store_id FROM sales')[0]->store_id);

        DB::update(
            'UPDATE store_inventories SET stock = 0 WHERE store_id = 2 AND variant_id = ?',
            [$variant->id]
        );

        $this->postJson('/api/sales/checkout', $payload)->assertUnprocessable();

        $this->assertSame(5, (int) DB::select(
            'SELECT stock FROM store_inventories WHERE store_id = 1 AND variant_id = ?',
            [$variant->id]
        )[0]->stock);
        $this->assertSame(0, (int) DB::select(
            'SELECT stock FROM store_inventories WHERE store_id = 2 AND variant_id = ?',
            [$variant->id]
        )[0]->stock);
        $this->assertSame(1, (int) DB::select('SELECT COUNT(*) AS total FROM sales')[0]->total);
    }

    public function test_public_checkout_rate_limits_repeated_requests()
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/sales/checkout', [
                'customer_phone' => '999000001',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/sales/checkout', [
            'customer_phone' => '999000001',
        ])->assertTooManyRequests();

        $this->postJson('/api/sales/checkout', [
            'customer_phone' => '999000002',
        ])->assertUnprocessable();
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
            'store_id' => 1,
            'payment_method_id' => 1,
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
            'store_id' => 1,
            'payment_method_id' => 1,
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

    public function test_banner_update_keeps_the_old_image_if_new_upload_fails()
    {
        $this->actingAs(User::find(1));
        $oldImageUrl = 'https://res.cloudinary.com/example/image/upload/old-banner.jpg';
        DB::insert(
            'INSERT INTO banners (image_url, is_active, sort_order, created_at, updated_at)
             VALUES (?, 1, 1, ?, ?)',
            [$oldImageUrl, now(), now()]
        );
        $bannerId = (int) DB::getPdo()->lastInsertId();

        $this->mock(\App\Services\CloudinaryService::class, function ($mock) {
            $mock->shouldReceive('upload')
                ->once()
                ->andThrow(new \RuntimeException('simulated upload failure'));
            $mock->shouldNotReceive('delete');
        });

        $this->post("/api/banners/{$bannerId}", [
            'image' => UploadedFile::fake()->image('new-banner.jpg'),
            'sort_order' => 1,
        ], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertSame($oldImageUrl, DB::select(
            'SELECT image_url FROM banners WHERE id = ?',
            [$bannerId]
        )[0]->image_url);
    }

    public function test_banner_update_reports_pending_cleanup_after_saving_new_image()
    {
        $this->actingAs(User::find(1));
        $oldImageUrl = 'https://res.cloudinary.com/example/image/upload/old-banner.jpg';
        $newImageUrl = 'https://res.cloudinary.com/example/image/upload/new-banner.jpg';
        DB::insert(
            'INSERT INTO banners (image_url, is_active, sort_order, created_at, updated_at)
             VALUES (?, 1, 1, ?, ?)',
            [$oldImageUrl, now(), now()]
        );
        $bannerId = (int) DB::getPdo()->lastInsertId();

        $this->mock(\App\Services\CloudinaryService::class, function ($mock) use ($bannerId, $oldImageUrl, $newImageUrl) {
            $mock->shouldReceive('upload')
                ->once()
                ->andReturn($newImageUrl);
            $mock->shouldReceive('delete')
                ->once()
                ->with($oldImageUrl)
                ->andReturnUsing(function () use ($bannerId, $newImageUrl) {
                    $this->assertSame($newImageUrl, DB::select(
                        'SELECT image_url FROM banners WHERE id = ?',
                        [$bannerId]
                    )[0]->image_url);

                    return false;
                });
        });

        $this->post("/api/banners/{$bannerId}", [
            'image' => UploadedFile::fake()->image('new-banner.jpg'),
            'sort_order' => 1,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('banner.image_url', $newImageUrl)
            ->assertJsonPath('storage_cleanup_pending', true);
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
        $createdNumberId = $response->json('data.id');

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
        $id = $createdNumberId;
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

    public function test_payment_method_changes_require_admin_role()
    {
        $seller = User::findOrFail(2);

        $this->actingAs($seller, 'sanctum')->postJson('/api/payment-methods', [
            'name' => 'Pago no autorizado',
        ])->assertForbidden();

        $this->actingAs($seller, 'sanctum')->putJson('/api/payment-methods/1', [
            'name' => 'Efectivo alterado',
        ])->assertForbidden();

        $this->actingAs($seller, 'sanctum')->postJson('/api/payment-methods/1/toggle')
            ->assertForbidden();

        $admin = User::findOrFail(1);
        $this->actingAs($admin, 'sanctum')->postJson('/api/payment-methods', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
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
            ],
            'payment_method_id' => 1,
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
            'store_id' => 1,
            'payment_method_id' => 1,
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
            'store_id' => 1,
            'payment_method_id' => 1,
        ]);
        $response->assertStatus(201);

        // Sale 2: Completed, then we cancel it
        $response = $this->actingAs($admin)->postJson('/api/sales', [
            'items' => [
                ['sku' => $variant, 'quantity' => 1, 'price' => 200.00]
            ],
            'store_id' => 1,
            'payment_method_id' => 1,
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
