<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class ProductRepository
{
    public function getPaginated(int $page = 1, int $perPage = 10, string $search = '', ?int $storeId = null)
    {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $countParams = [];

        if ($storeId) {
            $countQuery = "
                SELECT COUNT(DISTINCT p.id) as total 
                FROM products p 
                LEFT JOIN product_variants pv ON p.id = pv.product_id
                WHERE p.store_id = ?
            ";
            $countParams[] = $storeId;

            if ($search !== '') {
                $countQuery .= " AND (p.name LIKE ? OR p.base_sku LIKE ? OR pv.sku LIKE ?)";
                $countParams[] = "%$search%";
                $countParams[] = "%$search%";
                $countParams[] = "%$search%";
            }

            $selectQuery = "
                SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       (COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                       ), 0) > 0) as is_available,
                       COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                       ), 0) as total_stock
                FROM products p
                LEFT JOIN product_variants pv ON p.id = pv.product_id
                WHERE p.store_id = ?
            ";
            $params[] = $storeId;
        } else {
            $countQuery = "
                SELECT COUNT(DISTINCT p.id) as total 
                FROM products p 
                LEFT JOIN product_variants pv ON p.id = pv.product_id
                WHERE 1=1
            ";
            
            if ($search !== '') {
                $countQuery .= " AND (p.name LIKE ? OR p.base_sku LIKE ? OR pv.sku LIKE ?)";
                $countParams[] = "%$search%";
                $countParams[] = "%$search%";
                $countParams[] = "%$search%";
            }

            $selectQuery = "
                SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       (COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                       ), 0) > 0) as is_available,
                       COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                       ), 0) as total_stock
                FROM products p
                LEFT JOIN product_variants pv ON p.id = pv.product_id
                WHERE 1=1
            ";
        }

        if ($search !== '') {
            $selectQuery .= " AND (p.name LIKE ? OR p.base_sku LIKE ? OR pv.sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $selectQuery .= " GROUP BY p.id ORDER BY p.id DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $totalCount = DB::select($countQuery, $countParams)[0]->total;
        $data = DB::select($selectQuery, $params);
        $lastPage = (int) ceil($totalCount / $perPage);

        return [
            'data' => $data,
            'total' => $totalCount,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage > 0 ? $lastPage : 1
        ];
    }

    public function getAll(?int $storeId = null)
    {
        if ($storeId) {
            return DB::select("
                SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       (COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                       ), 0) > 0) as is_available,
                       COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                       ), 0) as total_stock
                FROM products p 
                WHERE p.store_id = ?
                GROUP BY p.id
                ORDER BY p.id DESC
            ", [$storeId]);
        }

        return DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   (COALESCE((
                       SELECT SUM(si.stock) 
                       FROM store_inventories si 
                       INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                   ), 0) > 0) as is_available,
                   COALESCE((
                       SELECT SUM(si.stock) 
                       FROM store_inventories si 
                       INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id AND si.store_id = p.store_id
                   ), 0) as total_stock
            FROM products p 
            GROUP BY p.id
            ORDER BY p.id DESC
        ");
    }

    public function findBySku(string $sku, ?int $storeId = null)
    {
        $variant = DB::select("
            SELECT pv.id as variant_id, pv.sku as variant_sku, pv.size, pv.color, 
                   p.id as product_id, p.category_id, p.base_sku, p.name, p.price,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   COALESCE(
                       (SELECT stock FROM store_inventories WHERE variant_id = pv.id AND store_id = COALESCE(?, 1)), 
                   0) as stock
            FROM product_variants pv
            INNER JOIN products p ON pv.product_id = p.id
            WHERE pv.sku = ? LIMIT 1
        ", [$storeId, $sku]);

        if (!empty($variant)) {
            return $variant[0];
        }

        $product = DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.description,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   COALESCE((
                       SELECT SUM(si.stock) 
                       FROM store_inventories si 
                       INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id 
                       " . ($storeId ? "AND si.store_id = $storeId" : "") . "
                   ), 0) as total_stock
            FROM products p 
            WHERE p.base_sku = ? LIMIT 1
        ", [$sku]);

        if (empty($product)) {
            return null;
        }

        $product = $product[0];
        $product->variants = DB::select("
            SELECT pv.id, pv.sku, pv.size, pv.color,
                   COALESCE((SELECT stock FROM store_inventories WHERE variant_id = pv.id AND store_id = COALESCE(?, 1)), 0) as stock
            FROM product_variants pv
            WHERE pv.product_id = ?
        ", [$storeId, $product->id]);

        foreach ($product->variants as $v) {
            $v->inventories = DB::select("
                SELECT si.store_id, s.name as store_name, si.stock
                FROM store_inventories si
                INNER JOIN stores s ON si.store_id = s.id
                WHERE si.variant_id = ?
            ", [$v->id]);
        }

        $product->images = DB::select("
            SELECT id, image_url, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC
        ", [$product->id]);

        return $product;
    }

    public function create(array $data, int $storeId = 1)
    {
        DB::beginTransaction();
        try {
            DB::insert("
                INSERT INTO products (base_sku, category_id, store_id, name, description, price, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $data['base_sku'],
                $data['category_id'],
                $storeId,
                $data['name'],
                $data['description'] ?? null,
                (float) $data['price']
            ]);

            $productId = DB::getPdo()->lastInsertId();

            if (!empty($data['images'])) {
                foreach ($data['images'] as $img) {
                    DB::insert("
                        INSERT INTO product_images (product_id, image_url, is_primary, created_at, updated_at) 
                        VALUES (?, ?, ?, NOW(), NOW())
                    ", [
                        $productId,
                        $img['url'],
                        $img['is_primary'] ? 1 : 0
                    ]);
                }
            }

            foreach ($data['variants'] as $v) {
                DB::insert("
                    INSERT INTO product_variants (product_id, sku, size, color, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ", [
                    $productId,
                    $v['sku'],
                    $v['size'],
                    $v['color']
                ]);
                
                $variantId = DB::getPdo()->lastInsertId();

                DB::insert("
                    INSERT INTO store_inventories (store_id, variant_id, stock, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ", [
                    $storeId,
                    $variantId,
                    $v['stock']
                ]);
            }

            DB::commit();
            return $this->findBySku($data['base_sku']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(string $baseSku, array $data, int $storeId = 1)
    {
        DB::beginTransaction();
        try {
            DB::update("
                UPDATE products 
                SET category_id = ?, store_id = ?, name = ?, description = ?, price = ?, updated_at = NOW() 
                WHERE base_sku = ?
            ", [
                $data['category_id'],
                $storeId,
                $data['name'],
                $data['description'] ?? null,
                (float) $data['price'],
                $baseSku
            ]);

            $product = DB::select("SELECT id FROM products WHERE base_sku = ?", [$baseSku])[0];

            // Ensure product variants only have inventory in the newly assigned store
            DB::delete("
                DELETE FROM store_inventories 
                WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id = ?) 
                AND store_id != ?
            ", [$product->id, $storeId]);

            foreach ($data['variants'] as $v) {
                if (!empty($v['id'])) {
                    DB::update("
                        UPDATE product_variants 
                        SET sku = ?, size = ?, color = ?, updated_at = NOW() 
                        WHERE id = ?
                    ", [
                        $v['sku'],
                        $v['size'],
                        $v['color'],
                        $v['id']
                    ]);
                    
                    $exists = DB::select("SELECT id FROM store_inventories WHERE store_id = ? AND variant_id = ?", [$storeId, $v['id']]);
                    if (empty($exists)) {
                        DB::insert("
                            INSERT INTO store_inventories (store_id, variant_id, stock, created_at, updated_at)
                            VALUES (?, ?, ?, NOW(), NOW())
                        ", [$storeId, $v['id'], $v['stock']]);
                    } else {
                        DB::update("
                            UPDATE store_inventories SET stock = ?, updated_at = NOW()
                            WHERE store_id = ? AND variant_id = ?
                        ", [$v['stock'], $storeId, $v['id']]);
                    }
                } else {
                    DB::insert("
                        INSERT INTO product_variants (product_id, sku, size, color, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ", [
                        $product->id,
                        $v['sku'],
                        $v['size'],
                        $v['color']
                    ]);
                    
                    $variantId = DB::getPdo()->lastInsertId();

                    DB::insert("
                        INSERT INTO store_inventories (store_id, variant_id, stock, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ", [
                        $storeId,
                        $variantId,
                        $v['stock']
                    ]);
                }
            }

            DB::commit();
            return $this->findBySku($baseSku);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(string $baseSku)
    {
        return DB::delete("DELETE FROM products WHERE base_sku = ?", [$baseSku]);
    }
}
