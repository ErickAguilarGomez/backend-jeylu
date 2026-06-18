<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class ProductRepository
{
    public function getPaginated(int $page = 1, int $perPage = 10, string $search = '', ?int $storeId = null, ?int $categoryId = null)
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

            if ($categoryId) {
                $countQuery .= " AND p.category_id = ?";
                $countParams[] = $categoryId;
            }

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

            if ($categoryId) {
                $selectQuery .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }
        } else {
            $countQuery = "
                SELECT COUNT(DISTINCT p.id) as total 
                FROM products p 
                LEFT JOIN product_variants pv ON p.id = pv.product_id
                WHERE 1=1
            ";

            if ($categoryId) {
                $countQuery .= " AND p.category_id = ?";
                $countParams[] = $categoryId;
            }
            
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

            if ($categoryId) {
                $selectQuery .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }
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

    public function getAll(?int $storeId = null, ?int $categoryId = null)
    {
        $params = [];
        $whereClauses = [];

        if ($storeId) {
            $whereClauses[] = "p.store_id = ?";
            $params[] = $storeId;
        }

        if ($categoryId) {
            $whereClauses[] = "p.category_id = ?";
            $params[] = $categoryId;
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

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
            $whereSql
            GROUP BY p.id
            ORDER BY p.id DESC
        ", $params);
    }

    public function findBySku(string $sku, ?int $storeId = null)
    {
        $variant = DB::select("
            SELECT pv.id as variant_id, pv.sku as variant_sku, pv.size, pv.color, 
                   p.id as product_id, p.category_id, p.base_sku, p.name, p.price,
                   c.name as category_name, s.name as store_name, s.address as store_address, s.phone as store_phone,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   COALESCE(
                       (SELECT stock FROM store_inventories WHERE variant_id = pv.id AND store_id = COALESCE(?, 1)), 
                   0) as stock
            FROM product_variants pv
            INNER JOIN products p ON pv.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN stores s ON p.store_id = s.id
            WHERE pv.sku = ? LIMIT 1
        ", [$storeId, $sku]);

        if (!empty($variant)) {
            return $variant[0];
        }

        $product = DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.description,
                   c.name as category_name, s.name as store_name, s.address as store_address, s.phone as store_phone,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   COALESCE((
                       SELECT SUM(si.stock) 
                       FROM store_inventories si 
                       INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id 
                       " . ($storeId ? "AND si.store_id = $storeId" : "") . "
                   ), 0) as total_stock
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN stores s ON p.store_id = s.id
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

        $skuParts = explode('-', $product->sku);
        if (count($skuParts) > 1) {
            array_pop($skuParts);
            $prefix = implode('-', $skuParts) . '-%';
            $product->other_colors = DB::select("
                SELECT p.id, p.base_sku as sku, p.name, p.price,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url
                FROM products p
                WHERE p.base_sku LIKE ? AND p.id != ?
            ", [$prefix, $product->id]);
        } else {
            $product->other_colors = [];
        }

        return $product;
    }

    public function create(array $data, int $storeId = 1)
    {
        DB::beginTransaction();
        try {
            $userId = auth()->id();
            DB::insert("
                INSERT INTO products (base_sku, category_id, store_id, name, description, price, created_by, updated_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $data['base_sku'],
                $data['category_id'],
                $storeId,
                $data['name'],
                $data['description'] ?? null,
                (float) $data['price'],
                $userId,
                $userId
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
                    INSERT INTO store_inventories (store_id, variant_id, stock, last_inventoried_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ", [
                    $storeId,
                    $variantId,
                    $v['stock'],
                    $userId
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
            $userId = auth()->id();
            DB::update("
                UPDATE products 
                SET category_id = ?, store_id = ?, name = ?, description = ?, price = ?, updated_by = ?, updated_at = NOW() 
                WHERE base_sku = ?
            ", [
                $data['category_id'],
                $storeId,
                $data['name'],
                $data['description'] ?? null,
                (float) $data['price'],
                $userId,
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
                            INSERT INTO store_inventories (store_id, variant_id, stock, last_inventoried_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, NOW(), NOW())
                        ", [$storeId, $v['id'], $v['stock'], $userId]);
                    } else {
                        DB::update("
                            UPDATE store_inventories SET stock = ?, last_inventoried_by = ?, updated_at = NOW()
                            WHERE store_id = ? AND variant_id = ?
                        ", [$v['stock'], $userId, $storeId, $v['id']]);
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
                        INSERT INTO store_inventories (store_id, variant_id, stock, last_inventoried_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ", [
                        $storeId,
                        $variantId,
                        $v['stock'],
                        $userId
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

    public function getBestSellers()
    {
        $bestSellers = DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   (COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id AND si2.store_id = p.store_id
                   ), 0) > 0) as is_available,
                   COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id AND si2.store_id = p.store_id
                   ), 0) as total_stock,
                   SUM(si.quantity) as total_sales
            FROM products p
            INNER JOIN product_variants pv ON p.id = pv.product_id
            INNER JOIN sale_items si ON si.variant_id = pv.id
            GROUP BY p.id
            ORDER BY total_sales DESC
            LIMIT 10
        ");

        if (count($bestSellers) >= 10) {
            return $bestSellers;
        }

        // We need to fill up the remaining spots with random products
        $needed = 10 - count($bestSellers);
        $excludeIds = array_map(function($p) { return $p->id; }, $bestSellers);
        
        $driver = DB::connection()->getDriverName();
        $randomOrder = $driver === 'sqlite' ? 'RANDOM()' : 'RAND()';

        $excludeClause = '';
        $params = [];
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = "AND p.id NOT IN ($placeholders)";
            $params = $excludeIds;
        }

        $randomProducts = DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   (COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id AND si2.store_id = p.store_id
                   ), 0) > 0) as is_available,
                   COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id AND si2.store_id = p.store_id
                   ), 0) as total_stock,
                   0 as total_sales
            FROM products p
            WHERE 1=1 $excludeClause
            ORDER BY $randomOrder
            LIMIT $needed
        ", $params);

        return array_merge($bestSellers, $randomProducts);
    }
}
