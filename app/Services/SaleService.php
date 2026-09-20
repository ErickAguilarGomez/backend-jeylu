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

    public function processSale(int $sellerId, int $storeId, array $items, ?int $customerId = null, ?string $customerName = null, ?int $paymentMethodId = null)
    {
        DB::beginTransaction();

        try {
            $paymentMethodName = 'Efectivo';
            if ($paymentMethodId) {
                $pm = DB::select("SELECT id, name, is_active FROM payment_methods WHERE id = ? LIMIT 1", [$paymentMethodId]);
                if (empty($pm) || !$pm[0]->is_active) {
                    throw new Exception("La forma de pago seleccionada no está activa o no es válida.");
                }
                $paymentMethodName = $pm[0]->name;
            }

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
                $commissionAmount,
                $paymentMethodId,
                $paymentMethodName
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

    public function processCheckoutSale(array $data)
    {
        DB::beginTransaction();

        try {
            $items = $data['items'] ?? [];
            if (empty($items)) {
                throw new Exception("El carrito de compras está vacío.");
            }

            $customerName = trim($data['customer_name'] ?? '');
            $customerPhone = trim($data['customer_phone'] ?? '');
            $customerEmail = trim($data['customer_email'] ?? '');
            $deliveryType = $data['delivery_type'] ?? 'pickup';
            $deliveryAddress = $data['delivery_address'] ?? null;
            $orderNotes = $data['order_notes'] ?? null;
            $storeId = !empty($data['store_id']) ? (int) $data['store_id'] : 1;
            
            // Asignar al usuario administrador/sistema para ventas de la tienda virtual
            $sellerId = 1;

            $paymentMethodName = 'Tarjeta de Crédito / Débito';
            $paymentMethodId = null;
            $pm = DB::select("SELECT id, name FROM payment_methods WHERE (name LIKE '%Tarjeta%' OR name LIKE '%Credito%' OR name LIKE '%Crédito%') AND is_active = 1 LIMIT 1");
            if (!empty($pm)) {
                $paymentMethodId = $pm[0]->id;
                $paymentMethodName = $pm[0]->name;
            }

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
                    if (!empty($item['productId'])) {
                        $variant = DB::select("
                            SELECT pv.id as variant_id, p.name, p.price 
                            FROM product_variants pv
                            INNER JOIN products p ON pv.product_id = p.id
                            WHERE p.id = ? " . (!empty($item['size']) ? "AND pv.size = ?" : "") . "
                            LIMIT 1
                        ", !empty($item['size']) ? [$item['productId'], $item['size']] : [$item['productId']]);
                    }
                }

                if (empty($variant)) {
                    throw new Exception("Producto o variante no encontrada (SKU: {$variantSku}).");
                }
                $variant = $variant[0];

                // Verificar stock en inventario de tienda
                $lockSql = DB::connection()->getDriverName() === 'sqlite' ? "" : " FOR UPDATE";
                $storeStock = DB::select("SELECT * FROM store_inventories WHERE store_id = ? AND variant_id = ?" . $lockSql, [$storeId, $variant->variant_id]);

                if (empty($storeStock) || $storeStock[0]->stock < $quantity) {
                    // Buscar tienda con stock suficiente si la tienda seleccionada no tiene
                    $altStock = DB::select("SELECT store_id, stock FROM store_inventories WHERE variant_id = ? AND stock >= ? ORDER BY stock DESC LIMIT 1" . $lockSql, [$variant->variant_id, $quantity]);
                    if (!empty($altStock)) {
                        $storeId = $altStock[0]->store_id;
                    } else {
                        throw new Exception("Stock insuficiente para {$variant->name} (Talle: " . ($item['size'] ?? 'Único') . ").");
                    }
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
                null,
                $customerName,
                $total,
                $processedItems,
                0.00,
                0.00,
                $paymentMethodId,
                $paymentMethodName,
                [],
                $customerPhone,
                $customerEmail,
                $deliveryType,
                $deliveryAddress,
                $orderNotes,
                'PENDING'
            );

            DB::commit();

            return [
                'success' => true,
                'sale_id' => $saleId,
                'total' => $total,
                'dispatch_status' => 'PENDING',
                'delivery_type' => $deliveryType,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'message' => '¡Compra realizada con éxito! Tu pedido ha sido registrado para despacho.'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
