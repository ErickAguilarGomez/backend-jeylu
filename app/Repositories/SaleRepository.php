<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class SaleRepository
{
    public function getPaginated(int $page = 1, int $perPage = 10, ?int $storeId = null, string $search = '', ?int $sellerId = null, ?string $startDate = null, ?string $endDate = null)
    {
        $offset = ($page - 1) * $perPage;

        $countQuery = "SELECT COUNT(*) as total FROM sales s";
        $countQuery .= " LEFT JOIN users seller ON s.seller_id = seller.id ";
        $countQuery .= " WHERE 1=1 ";
        
        $countParams = [];
        if ($storeId) {
            $countQuery .= " AND s.store_id = ?";
            $countParams[] = $storeId;
        }
        if ($sellerId) {
            $countQuery .= " AND s.seller_id = ?";
            $countParams[] = $sellerId;
        }
        if ($startDate) {
            $countQuery .= " AND s.created_at >= ?";
            $countParams[] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $countQuery .= " AND s.created_at <= ?";
            $countParams[] = $endDate . ' 23:59:59';
        }

        if ($search !== '') {
            $countQuery .= " AND (s.customer_name LIKE ? OR seller.name LIKE ?)";
            $countParams = array_merge($countParams, ["%$search%", "%$search%"]);
        }

        $totalCount = DB::select($countQuery, $countParams)[0]->total;

        $query = "
            SELECT s.*, 
                   s.total as total_amount,
                   st.name as store_name,
                   seller.name as seller_name, 
                   customer.name as customer_account_name
            FROM sales s 
            INNER JOIN stores st ON s.store_id = st.id
            INNER JOIN users seller ON s.seller_id = seller.id
            LEFT JOIN users customer ON s.customer_id = customer.id
            WHERE 1=1
        ";
        $params = [];

        if ($storeId) {
            $query .= " AND s.store_id = ?";
            $params[] = $storeId;
        }
        if ($sellerId) {
            $query .= " AND s.seller_id = ?";
            $params[] = $sellerId;
        }
        if ($startDate) {
            $query .= " AND s.created_at >= ?";
            $params[] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $query .= " AND s.created_at <= ?";
            $params[] = $endDate . ' 23:59:59';
        }

        if ($search !== '') {
            $query .= " AND (s.customer_name LIKE ? OR seller.name LIKE ?)";
            $params = array_merge($params, ["%$search%", "%$search%"]);
        }

        $query .= " ORDER BY s.id DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $sales = DB::select($query, $params);

        foreach ($sales as $sale) {
            $sale->items = DB::select("
                SELECT si.*, pv.sku as variant_sku, pv.size, p.name as product_name 
                FROM sale_items si 
                INNER JOIN product_variants pv ON si.variant_id = pv.id
                INNER JOIN products p ON pv.product_id = p.id
                WHERE si.sale_id = ?
            ", [$sale->id]);
        }

        $lastPage = (int) ceil($totalCount / $perPage);

        return [
            'data' => $sales,
            'total' => $totalCount,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage > 0 ? $lastPage : 1
        ];
    }

    public function getStats(?int $storeId = null, ?int $sellerId = null, ?string $startDate = null, ?string $endDate = null)
    {
        $query = "SELECT COALESCE(SUM(total), 0) as total_amount, COUNT(*) as total_sales FROM sales WHERE 1=1";
        $params = [];

        if ($storeId) {
            $query .= " AND store_id = ?";
            $params[] = $storeId;
        }
        if ($sellerId) {
            $query .= " AND seller_id = ?";
            $params[] = $sellerId;
        }
        if ($startDate) {
            $query .= " AND created_at >= ?";
            $params[] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $query .= " AND created_at <= ?";
            $params[] = $endDate . ' 23:59:59';
        }

        $result = DB::select($query, $params);
        return !empty($result) ? $result[0] : (object)['total_amount' => 0.00, 'total_sales' => 0];
    }

    public function persistSaleAndReduceStock(int $storeId, int $sellerId, ?int $customerId, ?string $customerName, float $total, array $processedItems): int
    {
        DB::insert("
            INSERT INTO sales (store_id, seller_id, customer_id, customer_name, total, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, 'COMPLETED', NOW(), NOW())
        ", [$storeId, $sellerId, $customerId, $customerName, $total]);
        
        $saleId = (int) DB::getPdo()->lastInsertId();

        foreach ($processedItems as $pItem) {
            DB::insert("
                INSERT INTO sale_items (sale_id, variant_id, quantity, price, subtotal, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $saleId,
                $pItem['variant_id'],
                $pItem['quantity'],
                $pItem['price'],
                $pItem['subtotal']
            ]);

            // Database level safe decrement to prevent negative stock in case of race conditions
            $affected = DB::update("
                UPDATE store_inventories 
                SET stock = stock - ? 
                WHERE store_id = ? AND variant_id = ? AND stock >= ?
            ", [
                $pItem['quantity'],
                $storeId,
                $pItem['variant_id'],
                $pItem['quantity']
            ]);

            if ($affected === 0) {
                throw new \Exception("El stock de uno de los productos cambió concurrentemente o es insuficiente. Venta cancelada.");
            }
        }

        return $saleId;
    }
}
