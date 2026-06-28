<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class ProductRepository
{
    public function getPaginated(int $page = 1, int $perPage = 10, string $search = '', ?int $storeId = null, ?int $categoryId = null, bool $includeDeleted = false)
    {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $countParams = [];

        $countQuery = "
            SELECT COUNT(DISTINCT p.id) as total 
            FROM products p 
            LEFT JOIN product_variants pv ON p.id = pv.product_id
            WHERE 1=1
        ";

        if (!$includeDeleted) {
            $countQuery .= " AND p.deleted_at IS NULL";
        }

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

        if ($storeId) {
            $selectQuery = "
                SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       (COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = ?
                       ), 0) > 0) as is_available,
                       COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = ?
                       ), 0) as total_stock
                FROM products p
                LEFT JOIN product_variants pv ON p.id = pv.product_id
                WHERE 1=1
            ";
            if (!$includeDeleted) {
                $selectQuery .= " AND p.deleted_at IS NULL";
            }
            $params[] = $storeId;
            $params[] = $storeId;
        } else {
            $selectQuery = "
                SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       (COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id
                       ), 0) > 0) as is_available,
                       COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id
                       ), 0) as total_stock
                FROM products p
                LEFT JOIN product_variants pv ON p.id = pv.product_id
                WHERE 1=1
            ";
            if (!$includeDeleted) {
                $selectQuery .= " AND p.deleted_at IS NULL";
            }
        }

        if ($categoryId) {
            $selectQuery .= " AND p.category_id = ?";
            $params[] = $categoryId;
        }

        if ($search !== '') {
            $selectQuery .= " AND (p.name LIKE ? OR p.base_sku LIKE ? OR pv.sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $selectQuery .= " GROUP BY p.id, p.category_id, p.store_id, p.base_sku, p.name, p.price, p.purchase_price, p.deleted_at ORDER BY p.id DESC LIMIT ? OFFSET ?";
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
        $whereClauses = ["p.deleted_at IS NULL"];

        if ($categoryId) {
            $whereClauses[] = "p.category_id = ?";
            $params[] = $categoryId;
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        if ($storeId) {
            $sql = "
                SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       (COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = ?
                       ), 0) > 0) as is_available,
                       COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id AND si.store_id = ?
                       ), 0) as total_stock
                FROM products p 
                $whereSql
                GROUP BY p.id, p.category_id, p.store_id, p.base_sku, p.name, p.price, p.purchase_price, p.deleted_at
                ORDER BY p.id DESC
            ";
            array_unshift($params, $storeId, $storeId);
        } else {
            $sql = "
                SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       (COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id
                       ), 0) > 0) as is_available,
                       COALESCE((
                           SELECT SUM(si.stock) 
                           FROM store_inventories si 
                           INNER JOIN product_variants pv2 ON si.variant_id = pv2.id 
                           WHERE pv2.product_id = p.id
                       ), 0) as total_stock
                FROM products p 
                $whereSql
                GROUP BY p.id, p.category_id, p.store_id, p.base_sku, p.name, p.price, p.purchase_price, p.deleted_at
                ORDER BY p.id DESC
            ";
        }

        return DB::select($sql, $params);
    }

    public function findBySku(string $sku, ?int $storeId = null)
    {
        if ($storeId) {
            $variant = DB::select("
                SELECT pv.id as variant_id, pv.sku as variant_sku, pv.size, pv.color, 
                       p.id as product_id, p.category_id, p.base_sku, p.name, p.price, p.purchase_price, p.deleted_at,
                       c.name as category_name, s.name as store_name, s.address as store_address, s.phone as store_phone,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       COALESCE(
                           (SELECT stock FROM store_inventories WHERE variant_id = pv.id AND store_id = ?), 
                       0) as stock
                FROM product_variants pv
                INNER JOIN products p ON pv.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN stores s ON p.store_id = s.id
                WHERE pv.sku = ? LIMIT 1
            ", [$storeId, $sku]);
        } else {
            $variant = DB::select("
                SELECT pv.id as variant_id, pv.sku as variant_sku, pv.size, pv.color, 
                       p.id as product_id, p.category_id, p.base_sku, p.name, p.price, p.purchase_price, p.deleted_at,
                       c.name as category_name, s.name as store_name, s.address as store_address, s.phone as store_phone,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                       COALESCE(
                           (SELECT SUM(stock) FROM store_inventories WHERE variant_id = pv.id), 
                       0) as stock
                FROM product_variants pv
                INNER JOIN products p ON pv.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN stores s ON p.store_id = s.id
                WHERE pv.sku = ? LIMIT 1
            ", [$sku]);
        }

        if (!empty($variant)) {
            $vObj = $variant[0];
            $baseProduct = $this->findBySku($vObj->base_sku, $storeId);
            if ($baseProduct) {
                $baseProduct->variant_sku = $vObj->variant_sku;
                $baseProduct->size = $vObj->size;
                $baseProduct->color = $vObj->color;
                $baseProduct->stock = $vObj->stock;
            }
            return $baseProduct;
        }

        $product = DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at, p.description, p.video_url,
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
                   COALESCE((SELECT " . ($storeId ? "stock FROM store_inventories WHERE variant_id = pv.id AND store_id = $storeId" : "SUM(stock) FROM store_inventories WHERE variant_id = pv.id") . "), 0) as stock
            FROM product_variants pv
            WHERE pv.product_id = ?
        ", [$product->id]);

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
                SELECT p.id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at,
                       (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url
                FROM products p
                WHERE p.base_sku LIKE ? AND p.id != ? AND p.deleted_at IS NULL
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
            $timestamp = now();
            DB::insert("
                INSERT INTO products (base_sku, category_id, store_id, name, description, video_url, price, purchase_price, created_by, updated_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $data['base_sku'],
                $data['category_id'],
                $storeId,
                $data['name'],
                $data['description'] ?? null,
                $data['video_url'] ?? null,
                (float) $data['price'],
                (float) $data['purchase_price'],
                $userId,
                $userId,
                $timestamp,
                $timestamp
            ]);

            $productId = DB::getPdo()->lastInsertId();

            if (!empty($data['images'])) {
                foreach ($data['images'] as $img) {
                    DB::insert("
                        INSERT INTO product_images (product_id, image_url, is_primary, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?)
                    ", [
                        $productId,
                        $img['url'],
                        $img['is_primary'] ? 1 : 0,
                        $timestamp,
                        $timestamp
                    ]);
                }
            }

            foreach ($data['variants'] as $v) {
                $sizeClean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $v['size']));
                $variantSku = $data['base_sku'] . '-' . $sizeClean;

                DB::insert("
                    INSERT INTO product_variants (product_id, sku, size, color, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ", [
                    $productId,
                    $variantSku,
                    $v['size'],
                    $v['color'] ?? null,
                    $timestamp,
                    $timestamp
                ]);
                
                $variantId = DB::getPdo()->lastInsertId();

                foreach ($v['stocks'] as $sId => $stock) {
                    DB::insert("
                        INSERT INTO store_inventories (store_id, variant_id, stock, last_inventoried_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ", [
                        $sId,
                        $variantId,
                        $stock,
                        $userId,
                        $timestamp,
                        $timestamp
                    ]);
                }
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
            $timestamp = now();
            DB::update("
                UPDATE products 
                SET category_id = ?, store_id = ?, name = ?, description = ?, video_url = ?, price = ?, purchase_price = ?, updated_by = ?, updated_at = ? 
                WHERE base_sku = ?
            ", [
                $data['category_id'],
                $storeId,
                $data['name'],
                $data['description'] ?? null,
                $data['video_url'] ?? null,
                (float) $data['price'],
                (float) $data['purchase_price'],
                $userId,
                $timestamp,
                $baseSku
            ]);

            $product = DB::select("SELECT id FROM products WHERE base_sku = ?", [$baseSku])[0];

            $updatedVariantIds = array_filter(array_column($data['variants'], 'id'));
            if (!empty($updatedVariantIds)) {
                $placeholders = implode(',', array_fill(0, count($updatedVariantIds), '?'));
                DB::delete("
                    DELETE FROM product_variants 
                    WHERE product_id = ? AND id NOT IN ($placeholders)
                ", array_merge([$product->id], $updatedVariantIds));
            } else {
                DB::delete("DELETE FROM product_variants WHERE product_id = ?", [$product->id]);
            }

            foreach ($data['variants'] as $v) {
                $sizeClean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $v['size']));
                $variantSku = $baseSku . '-' . $sizeClean;

                if (!empty($v['id'])) {
                    DB::update("
                        UPDATE product_variants 
                        SET sku = ?, size = ?, color = ?, updated_at = ? 
                        WHERE id = ?
                    ", [
                        $variantSku,
                        $v['size'],
                        $v['color'] ?? null,
                        $timestamp,
                        $v['id']
                    ]);
                    
                    $variantId = $v['id'];
                } else {
                    DB::insert("
                        INSERT INTO product_variants (product_id, sku, size, color, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ", [
                        $product->id,
                        $variantSku,
                        $v['size'],
                        $v['color'] ?? null,
                        $timestamp,
                        $timestamp
                    ]);
                    
                    $variantId = DB::getPdo()->lastInsertId();
                }

                foreach ($v['stocks'] as $sId => $stock) {
                    $exists = DB::select("SELECT id FROM store_inventories WHERE store_id = ? AND variant_id = ?", [$sId, $variantId]);
                    if (empty($exists)) {
                        DB::insert("
                            INSERT INTO store_inventories (store_id, variant_id, stock, last_inventoried_by, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ", [$sId, $variantId, $stock, $userId, $timestamp, $timestamp]);
                    } else {
                        DB::update("
                            UPDATE store_inventories SET stock = ?, last_inventoried_by = ?, updated_at = ?
                            WHERE store_id = ? AND variant_id = ?
                        ", [$stock, $userId, $timestamp, $sId, $variantId]);
                    }
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
        return DB::update("UPDATE products SET deleted_at = ? WHERE base_sku = ?", [now(), $baseSku]);
    }

    public function restore(string $baseSku)
    {
        return DB::update("UPDATE products SET deleted_at = NULL WHERE base_sku = ?", [$baseSku]);
    }

    public function getBestSellers()
    {
        $totalProducts = DB::select("SELECT COUNT(*) as total FROM products")[0]->total;
        if ($totalProducts == 0) {
            return [];
        }

        $bestSellers = DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   (COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id
                   ), 0) > 0) as is_available,
                   COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id
                   ), 0) as total_stock,
                   SUM(si.quantity) as total_sales
            FROM products p
            INNER JOIN product_variants pv ON p.id = pv.product_id
            INNER JOIN sale_items si ON si.variant_id = pv.id
            WHERE p.deleted_at IS NULL
            GROUP BY p.id, p.category_id, p.store_id, p.base_sku, p.name, p.price, p.purchase_price, p.deleted_at
            ORDER BY total_sales DESC
            LIMIT 10
        ");

        if (count($bestSellers) >= 10) {
            return $bestSellers;
        }

        $needed = 10 - count($bestSellers);
        $excludeIds = array_map(function($p) { return $p->id; }, $bestSellers);
        
        $excludeClause = '';
        $params = [];
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = "AND p.id NOT IN ($placeholders)";
            $params = $excludeIds;
        }

        $randomFunc = DB::connection()->getDriverName() === 'sqlite' ? 'RANDOM()' : 'RAND()';

        $randomProducts = DB::select("
            SELECT p.id, p.category_id, p.store_id, p.base_sku as sku, p.name, p.price, p.purchase_price, p.deleted_at,
                   (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as image_url,
                   (COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id
                   ), 0) > 0) as is_available,
                   COALESCE((
                       SELECT SUM(si2.stock) 
                       FROM store_inventories si2 
                       INNER JOIN product_variants pv2 ON si2.variant_id = pv2.id 
                       WHERE pv2.product_id = p.id
                   ), 0) as total_stock,
                   0 as total_sales
            FROM products p
            WHERE p.deleted_at IS NULL $excludeClause
            ORDER BY $randomFunc
            LIMIT $needed
        ", $params);

        return array_merge($bestSellers, $randomProducts);
    }
}
