<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->foreignId('store_id')->default(1)->constrained('stores')->onDelete('cascade');
            $table->string('base_sku', 100)->unique()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('image_url', 500);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('sku', 100)->unique()->index();
            $table->string('size', 50)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('qr_code_url', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('store_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('variant_id')->constrained('product_variants')->onDelete('cascade');
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedBigInteger('last_inventoried_by')->nullable();
            $table->timestamps();

            $table->foreign('last_inventoried_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['store_id', 'variant_id']);
        });

        $products = [
            ['base_sku' => 'JLU-SAMBA', 'name' => 'JEILU SNEAKERS SAMBA NEGRO/BLANCO', 'cat_id' => 1, 'price' => 529.00, 'img' => 'https://images.unsplash.com/photo-1515955656352-a1fa3ffcd111?w=800&auto=format&fit=crop&q=80', 'variants' => [['sku' => 'JLU-SAMBA-40', 'size' => '40'], ['sku' => 'JLU-SAMBA-41', 'size' => '41']], 'stock' => 10],
            ['base_sku' => 'JLU-GAZELLE', 'name' => 'JEILU URBAN GAZELLE INDOOR AZUL', 'cat_id' => 1, 'price' => 499.00, 'img' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800&auto=format&fit=crop&q=80', 'variants' => [['sku' => 'JLU-GAZELLE-39', 'size' => '39']], 'stock' => 15],
        ];

        foreach ($products as $p) {
            $exists = DB::select("SELECT id FROM products WHERE base_sku = ?", [$p['base_sku']]);
            if (empty($exists)) {
                DB::insert("INSERT INTO products (base_sku, category_id, store_id, name, price, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                    $p['base_sku'], $p['cat_id'], 1, $p['name'], $p['price'], 1, 1, now(), now()
                ]);
                $productId = DB::getPdo()->lastInsertId();

                DB::insert("INSERT INTO product_images (product_id, image_url, is_primary, created_at, updated_at) VALUES (?, ?, ?, ?, ?)", [
                    $productId, $p['img'], 1, now(), now()
                ]);

                foreach ($p['variants'] as $v) {
                    DB::insert("INSERT INTO product_variants (product_id, sku, size, created_at, updated_at) VALUES (?, ?, ?, ?, ?)", [
                        $productId, $v['sku'], $v['size'], now(), now()
                    ]);
                    $variantId = DB::getPdo()->lastInsertId();

                    DB::insert("INSERT INTO store_inventories (store_id, variant_id, stock, last_inventoried_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [
                        1, $variantId, $p['stock'], 1, now(), now()
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_inventories');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};
