<?php

namespace App\Services;

use App\Repositories\SaleRepository;
use Illuminate\Support\Facades\DB;
use Exception;

class SaleService
{
    protected $saleRepo;

    public function __construct(SaleRepository $saleRepo)
    {
        $this->saleRepo = $saleRepo;
    }

    public function processSale(int $sellerId, int $storeId, array $items, ?int $customerId = null, ?string $customerName = null)
    {
        DB::beginTransaction();

        try {
            if ($customerId && empty($customerName)) {
                $user = DB::select("SELECT name FROM users WHERE id = ? LIMIT 1", [$customerId]);
                if (!empty($user)) {
                    $customerName = $user[0]->name;
                }
            }

            $total = 0.00;
            $processedItems = [];

            $sellerData = DB::select("SELECT role_id, commission_percentage FROM users WHERE id = ? LIMIT 1", [$sellerId]);
            $commissionPercentage = 0.00;
            if (!empty($sellerData) && $sellerData[0]->role_id == 2) {
                $commissionPercentage = (float) $sellerData[0]->commission_percentage;
            }

            foreach ($items as $item) {
                $variantSku = $item['sku'];
                $quantity = (int) $item['quantity'];

                $variant = DB::select("
                    SELECT pv.id as variant_id, p.name, p.price 
                    FROM product_variants pv
                    INNER JOIN products p ON pv.product_id = p.id
                    WHERE pv.sku = ?
                ", [$variantSku]);

                if (empty($variant)) {
                    throw new Exception("Product variant with SKU {$variantSku} not found.");
                }
                $variant = $variant[0];

                $lockSql = DB::connection()->getDriverName() === 'sqlite' ? "" : " FOR UPDATE";
                $storeStock = DB::select("SELECT * FROM store_inventories WHERE store_id = ? AND variant_id = ?" . $lockSql, [$storeId, $variant->variant_id]);
                
                if (empty($storeStock)) {
                    throw new Exception("Product {$variant->name} (SKU: {$variantSku}) is not assigned to this store.");
                }

                $storeStock = $storeStock[0];

                if ($storeStock->stock < $quantity) {
                    throw new Exception("Insufficient stock for {$variant->name} (SKU: {$variantSku}). Available: {$storeStock->stock}");
                }

                $price = isset($item['price']) ? (float) $item['price'] : (float) $variant->price;
                $subtotal = $price * $quantity;
                $total += $subtotal;

                $processedItems[] = [
                    'variant_id' => $variant->variant_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $subtotal
                ];
            }

            $commissionAmount = $total * ($commissionPercentage / 100);

            $saleId = $this->saleRepo->persistSaleAndReduceStock(
                $storeId, 
                $sellerId, 
                $customerId, 
                $customerName, 
                $total, 
                $processedItems,
                $commissionPercentage,
                $commissionAmount
            );

            DB::commit();

            return [
                'success' => true,
                'sale_id' => $saleId,
                'total' => $total,
                'message' => 'Sale processed successfully.'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateSale(int $saleId, array $items, string $status, ?string $customerName, int $userId)
    {
        DB::beginTransaction();

        try {
            $sale = DB::select("SELECT * FROM sales WHERE id = ? LIMIT 1", [$saleId]);
            if (empty($sale)) {
                throw new Exception("Sale not found.");
            }
            $sale = $sale[0];
            $storeId = $sale->store_id;

            $oldItems = DB::select("SELECT * FROM sale_items WHERE sale_id = ?", [$saleId]);

            // Revert stock of old items only if they were actively consuming stock
            $oldWasActive = in_array($sale->status, ['COMPLETED', 'EXCHANGED']);
            if ($oldWasActive) {
                foreach ($oldItems as $oldItem) {
                    DB::update("
                        UPDATE store_inventories 
                        SET stock = stock + ? 
                        WHERE store_id = ? AND variant_id = ?
                    ", [$oldItem->quantity, $storeId, $oldItem->variant_id]);
                }
            }

            $deductStock = !in_array($status, ['CANCELLED', 'REFUNDED']);
            $total = 0.00;
            $processedItems = [];

            foreach ($items as $item) {
                $variantSku = $item['sku'];
                $quantity = (int) $item['quantity'];

                $variant = DB::select("
                    SELECT pv.id as variant_id, p.name, p.price 
                    FROM product_variants pv
                    INNER JOIN products p ON pv.product_id = p.id
                    WHERE pv.sku = ?
                ", [$variantSku]);

                if (empty($variant)) {
                    throw new Exception("Product variant with SKU {$variantSku} not found.");
                }
                $variant = $variant[0];

                if ($deductStock) {
                    $storeStock = DB::select("
                        SELECT stock 
                        FROM store_inventories 
                        WHERE store_id = ? AND variant_id = ?
                    ", [$storeId, $variant->variant_id]);

                    if (empty($storeStock)) {
                        throw new Exception("Product {$variant->name} (SKU: {$variantSku}) is not assigned to this store.");
                    }
                    $storeStock = $storeStock[0];

                    if ($storeStock->stock < $quantity) {
                        throw new Exception("Insufficient stock for {$variant->name} (SKU: {$variantSku}). Available: {$storeStock->stock}");
                    }
                }

                $price = isset($item['price']) ? (float) $item['price'] : (float) $variant->price;
                $subtotal = $price * $quantity;
                $total += $subtotal;

                $processedItems[] = [
                    'variant_id' => $variant->variant_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $subtotal
                ];
            }

            DB::delete("DELETE FROM sale_items WHERE sale_id = ?", [$saleId]);

            $now = now();
            foreach ($processedItems as $pItem) {
                DB::insert("
                    INSERT INTO sale_items (sale_id, variant_id, quantity, price, subtotal, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ", [
                    $saleId,
                    $pItem['variant_id'],
                    $pItem['quantity'],
                    $pItem['price'],
                    $pItem['subtotal'],
                    $now,
                    $now
                ]);

                if ($deductStock) {
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
                        throw new Exception("El stock de uno de los productos cambió concurrentemente. Venta cancelada.");
                    }
                }
            }

            $commissionPercentage = (float) $sale->commission_percentage;
            $commissionAmount = $total * ($commissionPercentage / 100);

            DB::update("
                UPDATE sales 
                SET total = ?, 
                    status = ?, 
                    customer_name = ?, 
                    commission_amount = ?, 
                    updated_by = ?, 
                    updated_at = ? 
                WHERE id = ?
            ", [$total, $status, $customerName, $commissionAmount, $userId, $now, $saleId]);

            DB::commit();

            return [
                'success' => true,
                'sale_id' => $saleId,
                'total' => $total,
                'message' => 'Sale updated successfully.'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
