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

                $storeStock = DB::select("SELECT * FROM store_inventories WHERE store_id = ? AND variant_id = ? FOR UPDATE", [$storeId, $variant->variant_id]);
                
                if (empty($storeStock)) {
                    throw new Exception("Product {$variant->name} (SKU: {$variantSku}) is not assigned to this store.");
                }

                $storeStock = $storeStock[0];

                if ($storeStock->stock < $quantity) {
                    throw new Exception("Insufficient stock for {$variant->name} (SKU: {$variantSku}). Available: {$storeStock->stock}");
                }

                $price = (float) $variant->price;
                $subtotal = $price * $quantity;
                $total += $subtotal;

                $processedItems[] = [
                    'variant_id' => $variant->variant_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $subtotal
                ];
            }

            $saleId = $this->saleRepo->persistSaleAndReduceStock(
                $storeId, 
                $sellerId, 
                $customerId, 
                $customerName, 
                $total, 
                $processedItems
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
}
