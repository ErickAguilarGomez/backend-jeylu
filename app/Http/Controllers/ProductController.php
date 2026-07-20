<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\ProductRepository;
use App\Services\CloudinaryService;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    protected $productRepo;
    protected $cloudinaryService;

    public function __construct(ProductRepository $productRepo, CloudinaryService $cloudinaryService)
    {
        $this->productRepo = $productRepo;
        $this->cloudinaryService = $cloudinaryService;
    }

    private function getStoreIdIfSeller()
    {
        $user = Auth::user();
        
        if (!$user || $user->role_id != 2) {
            return null;
        }

        $storeUser = DB::select("
            SELECT store_id FROM store_user 
            WHERE user_id = ? AND is_primary = 1 
            LIMIT 1
        ", [$user->id]);

        return !empty($storeUser) ? $storeUser[0]->store_id : null;
    }

    private function formatProducts(array $products, bool $keepStock = false): array
    {
        $genDiscount = DB::select("SELECT percentage, is_active FROM general_discounts ORDER BY id ASC LIMIT 1");
        $genPct = (!empty($genDiscount) && $genDiscount[0]->is_active) ? (float)$genDiscount[0]->percentage : 0.00;

        $categories = DB::select("SELECT id, discount_enabled, discount_percentage FROM categories");
        $catDiscountMap = [];
        foreach ($categories as $cat) {
            if ($cat->discount_enabled && $cat->discount_percentage > 0) {
                $catDiscountMap[$cat->id] = (float)$cat->discount_percentage;
            }
        }

        foreach ($products as $item) {
            $item->is_available = (bool) ($item->is_available ?? false);
            if (!$keepStock && property_exists($item, 'total_stock')) {
                unset($item->total_stock);
            }

            $originalPrice = (float) $item->price;
            $catId = $item->category_id ?? null;
            $discountPct = 0.00;
            $discountType = 'none';

            if ($catId && isset($catDiscountMap[$catId])) {
                $discountPct = $catDiscountMap[$catId];
                $discountType = 'category';
            } else if ($genPct > 0) {
                $discountPct = $genPct;
                $discountType = 'general';
            }

            $item->original_price = $originalPrice;
            $item->discount_percentage = $discountPct;
            $item->discount_type = $discountType;

            if ($discountPct > 0) {
                $discountAmount = round($originalPrice * ($discountPct / 100), 2);
                $item->price = max(0.00, round($originalPrice - $discountAmount, 2));
                $item->discounted_price = $item->price;
            } else {
                $item->discounted_price = $originalPrice;
            }
        }
        return $products;
    }

    public function index(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $search = $request->query('search', '');
        
        $storeId = $this->getStoreIdIfSeller();
        $categoryId = $request->query('category_id') ? (int) $request->query('category_id') : null;
        $includeDeleted = $request->query('include_deleted') == 1;

        $result = $this->productRepo->getPaginated($page, $perPage, $search, $storeId, $categoryId, $includeDeleted);

        return response()->json([
            'success' => true,
            'data' => $this->formatProducts($result['data'], false),
            'meta' => [
                'total' => $result['total'],
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page']
            ]
        ]);
    }

    public function all(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $search = $request->query('search', '');
        
        $storeId = $request->query('store_id', $this->getStoreIdIfSeller());
        $categoryId = $request->query('category_id') ? (int) $request->query('category_id') : null;

        if ($request->has('nopaginate')) {
            $products = $this->productRepo->getAll($storeId ? (int) $storeId : null, $categoryId);
            return response()->json([
                'success' => true,
                'data' => $this->formatProducts($products, true)
            ]);
        }

        $includeDeleted = $request->query('include_deleted') == 1;
        $result = $this->productRepo->getPaginated($page, $perPage, $search, $storeId ? (int) $storeId : null, $categoryId, $includeDeleted);

        return response()->json([
            'success' => true,
            'data' => $this->formatProducts($result['data'], true),
            'meta' => [
                'total' => $result['total'],
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page']
            ]
        ]);
    }

    public function bestSellers(Request $request)
    {
        $products = $this->productRepo->getBestSellers();
        return response()->json([
            'success' => true,
            'data' => $this->formatProducts($products, true)
        ]);
    }

    public function show(Request $request, string $sku)
    {
        $storeId = $this->getStoreIdIfSeller();
        if (!$storeId && Auth::check() && Auth::user()->role_id == 1 && $request->has('store_id')) {
            $storeId = (int) $request->query('store_id');
        }
        $product = $this->productRepo->findBySku($sku, $storeId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        $formatted = $this->formatProducts([$product], true);

        return response()->json([
            'success' => true,
            'data' => $formatted[0]
        ]);
    }

    public function destroyImage(Request $request)
    {
        $validated = $request->validate([
            'image_url' => ['required', 'string']
        ]);

        $this->cloudinaryService->delete($validated['image_url']);
        DB::delete("DELETE FROM product_images WHERE image_url = ?", [$validated['image_url']]);

        return response()->json([
            'success' => true,
            'message' => 'Imagen eliminada exitosamente del almacenamiento y base de datos.'
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'base_sku' => ['nullable', 'string', 'max:100'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'file', 'image', 'max:5120'],
            'image_urls' => ['nullable', 'array'],
            'image_urls.*' => ['nullable', 'string'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.size' => ['nullable', 'string', 'max:50'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.stocks' => ['required', 'array'],
            'variants.*.stocks.*' => ['required', 'integer', 'min:0'],
            
            // Purchase order fields
            'purchase_order_option' => ['nullable', 'string', 'in:none,new,existing'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'purchase_order_file' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
            'purchase_order_number' => ['nullable', 'string', 'max:255'],
            'purchase_order_provider' => ['nullable', 'string', 'max:255'],
            'purchase_order_date' => ['nullable', 'date'],
            'purchase_order_total' => ['nullable', 'numeric', 'min:0'],
            'purchase_order_status' => ['nullable', 'string', 'max:100'],
            'purchase_order_observations' => ['nullable', 'string'],
        ], [
            'variants.*.size.required' => 'La talla es obligatoria para todas las variantes.',
            'variants.*.stocks.required' => 'El stock por tienda es obligatorio.'
        ]);

        if (empty($validated['base_sku'])) {
            $cleanName = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($validated['name'])), 0, 4);
            if (empty($cleanName)) {
                $cleanName = 'PROD';
            }
            do {
                $randomNum = rand(1000, 9999);
                $baseSku = $cleanName . '-' . $randomNum;
                $exists = DB::select("SELECT id FROM products WHERE base_sku = ?", [$baseSku]);
            } while (!empty($exists));
            $validated['base_sku'] = $baseSku;
        } else {
            $existing = $this->productRepo->findBySku($validated['base_sku']);
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Base SKU already exists.'
                ], 422);
            }
        }

        $processedImages = [];
        
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                $url = $this->cloudinaryService->upload($file, 'ecommerce_products');
                $processedImages[] = [
                    'url' => $url,
                    'is_primary' => count($processedImages) === 0
                ];
            }
        }

        if (!empty($validated['image_urls'])) {
            foreach ($validated['image_urls'] as $url) {
                if (!empty($url)) {
                    $processedImages[] = [
                        'url' => $url,
                        'is_primary' => count($processedImages) === 0
                    ];
                }
            }
        }

        $processedVariants = [];
        foreach ($validated['variants'] as $index => $v) {
            $size = $v['size'] ?? null;
            $color = $v['color'] ?? null;
            
            $sizeClean = $size !== null && $size !== '' ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $size)) : 'U';
            $variantSku = $validated['base_sku'] . '-' . $sizeClean;

            $processedVariants[] = [
                'sku' => $variantSku,
                'size' => $size,
                'color' => $color,
                'stocks' => $v['stocks']
            ];
        }

        $purchaseOrderId = $this->resolvePurchaseOrderId($request, $validated);
        $storeId = (int) $validated['store_id'];

        $productData = [
            'base_sku' => $validated['base_sku'],
            'category_id' => $validated['category_id'],
            'purchase_order_id' => $purchaseOrderId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'video_url' => $validated['video_url'] ?? null,
            'price' => $validated['price'],
            'purchase_price' => $validated['purchase_price'] ?? 0.00,
            'images' => $processedImages,
            'variants' => $processedVariants
        ];

        $created = $this->productRepo->create($productData, $storeId);

        return response()->json([
            'success' => true,
            'data' => $created,
            'message' => 'Product created.'
        ], 201);
    }

    public function update(Request $request, string $baseSku)
    {
        $product = $this->productRepo->findBySku($baseSku);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'file', 'image', 'max:5120'],
            'image_urls' => ['nullable', 'array'],
            'image_urls.*' => ['nullable', 'string'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.size' => ['nullable', 'string', 'max:50'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.stocks' => ['required', 'array'],
            'variants.*.stocks.*' => ['required', 'integer', 'min:0'],
            
            // Purchase order fields
            'purchase_order_option' => ['nullable', 'string', 'in:none,new,existing'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'purchase_order_file' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
            'purchase_order_number' => ['nullable', 'string', 'max:255'],
            'purchase_order_provider' => ['nullable', 'string', 'max:255'],
            'purchase_order_date' => ['nullable', 'date'],
            'purchase_order_total' => ['nullable', 'numeric', 'min:0'],
            'purchase_order_status' => ['nullable', 'string', 'max:100'],
            'purchase_order_observations' => ['nullable', 'string'],
        ], [
            'variants.*.size.required' => 'La talla es obligatoria para todas las variantes.',
            'variants.*.stocks.required' => 'El stock por tienda es obligatorio.'
        ]);

        // Upload any new provided files
        if ($request->hasFile('images')) {
            $hasPrimary = DB::select("SELECT id FROM product_images WHERE product_id = ? AND is_primary = 1", [$product->id]);
            foreach ($request->file('images') as $file) {
                $url = $this->cloudinaryService->upload($file, 'ecommerce_products');
                DB::insert("
                    INSERT INTO product_images (product_id, image_url, is_primary, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ", [$product->id, $url, empty($hasPrimary) ? 1 : 0]);
                $hasPrimary = true;
            }
        }

        if (!empty($validated['image_urls'])) {
            $hasPrimary = DB::select("SELECT id FROM product_images WHERE product_id = ? AND is_primary = 1", [$product->id]);
            foreach ($validated['image_urls'] as $url) {
                if (!empty($url)) {
                    $exists = DB::select("SELECT id FROM product_images WHERE product_id = ? AND image_url = ?", [$product->id, $url]);
                    if (empty($exists)) {
                        DB::insert("
                            INSERT INTO product_images (product_id, image_url, is_primary, created_at, updated_at)
                            VALUES (?, ?, ?, NOW(), NOW())
                        ", [$product->id, $url, empty($hasPrimary) ? 1 : 0]);
                        $hasPrimary = true;
                    }
                }
            }
        }

        $processedVariants = [];
        foreach ($validated['variants'] as $index => $v) {
            $size = $v['size'] ?? null;
            $color = $v['color'] ?? null;
            
            $sizeClean = $size !== null && $size !== '' ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $size)) : 'U';
            $variantSku = $baseSku . '-' . $sizeClean;

            $processedVariants[] = [
                'id' => $v['id'] ?? null,
                'sku' => $variantSku,
                'size' => $size,
                'color' => $color,
                'stocks' => $v['stocks']
            ];
        }

        $purchaseOrderId = $this->resolvePurchaseOrderId($request, $validated);
        // If option is none, we explicitly clean it. If option is not provided, we keep the previous purchase_order_id.
        $updatePayload = [
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'price' => $validated['price'],
            'purchase_price' => $validated['purchase_price'] ?? 0.00,
            'description' => $validated['description'] ?? null,
            'video_url' => $validated['video_url'] ?? null,
            'variants' => $processedVariants
        ];

        if (isset($validated['purchase_order_option'])) {
            $updatePayload['purchase_order_id'] = $purchaseOrderId;
        } else {
            $updatePayload['purchase_order_id'] = $product->purchase_order_id;
        }

        $storeId = (int) $validated['store_id'];

        $updated = $this->productRepo->update($baseSku, $updatePayload, $storeId);

        return response()->json([
            'success' => true,
            'data' => $updated,
            'message' => 'Product updated.'
        ]);
    }

    public function destroy(string $sku)
    {
        $product = $this->productRepo->findBySku($sku);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        try {
            DB::beginTransaction();
            $this->productRepo->delete($sku);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el producto: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product deleted.'
        ]);
    }

    public function restore(string $sku)
    {
        try {
            DB::beginTransaction();
            $restored = $this->productRepo->restore($sku);
            DB::commit();

            if ($restored) {
                return response()->json([
                    'success' => true,
                    'message' => 'Product restored successfully.'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Product not found or already active.'
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener productos con stock bajo (<= 5 unidades) por tienda.
     */
    public function lowStock(Request $request)
    {
        $threshold = (int) $request->query('threshold', 5);

        $data = DB::select("
            SELECT 
                p.name AS product_name,
                p.base_sku,
                pv.sku AS variant_sku,
                pv.size,
                s.name AS store_name,
                si.stock
            FROM store_inventories si
            INNER JOIN product_variants pv ON pv.id = si.variant_id
            INNER JOIN products p ON p.id = pv.product_id
            INNER JOIN stores s ON s.id = si.store_id
            WHERE p.deleted_at IS NULL
              AND si.stock <= ?
            ORDER BY si.stock ASC, s.name ASC, p.name ASC
        ", [$threshold]);

        return response()->json([
            'success' => true,
            'data' => $data,
            'count' => count($data)
        ]);
    }

    private function resolvePurchaseOrderId(Request $request, array $validated)
    {
        $option = $validated['purchase_order_option'] ?? 'none';
        
        if ($option === 'none') {
            return null;
        }
        
        if ($option === 'existing') {
            $poId = $validated['purchase_order_id'] ?? null;
            if ($poId) {
                // Allow updating details of the existing order if provided
                $updateData = [];
                $updateParams = [];
                
                if ($request->hasFile('purchase_order_file')) {
                    $file = $request->file('purchase_order_file');
                    $fileUrl = $this->cloudinaryService->upload($file, 'purchase_orders');
                    $updateData[] = "file_url = ?";
                    $updateParams[] = $fileUrl;
                }
                
                if (isset($validated['purchase_order_number'])) {
                    $updateData[] = "order_number = ?";
                    $updateParams[] = $validated['purchase_order_number'];
                }
                if (isset($validated['purchase_order_provider'])) {
                    $updateData[] = "provider = ?";
                    $updateParams[] = $validated['purchase_order_provider'];
                }
                if (isset($validated['purchase_order_date'])) {
                    $updateData[] = "purchase_date = ?";
                    $updateParams[] = $validated['purchase_order_date'];
                }
                if (isset($validated['purchase_order_total'])) {
                    $updateData[] = "total_amount = ?";
                    $updateParams[] = $validated['purchase_order_total'];
                }
                if (isset($validated['purchase_order_status'])) {
                    $updateData[] = "status = ?";
                    $updateParams[] = $validated['purchase_order_status'];
                }
                if (isset($validated['purchase_order_observations'])) {
                    $updateData[] = "observations = ?";
                    $updateParams[] = $validated['purchase_order_observations'];
                }
                
                if (!empty($updateData)) {
                    $updateParams[] = $poId;
                    DB::update("
                        UPDATE purchase_orders 
                        SET " . implode(", ", $updateData) . ", updated_at = NOW() 
                        WHERE id = ?
                    ", $updateParams);
                }
                
                return $poId;
            }
            return null;
        }
        
        if ($option === 'new') {
            if ($request->hasFile('purchase_order_file')) {
                $file = $request->file('purchase_order_file');
                $fileUrl = $this->cloudinaryService->upload($file, 'purchase_orders');
                
                DB::insert("
                    INSERT INTO purchase_orders (order_number, file_url, provider, purchase_date, total_amount, observations, status, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ", [
                    $validated['purchase_order_number'] ?? null,
                    $fileUrl,
                    $validated['purchase_order_provider'] ?? null,
                    $validated['purchase_order_date'] ?? null,
                    $validated['purchase_order_total'] ?? null,
                    $validated['purchase_order_observations'] ?? null,
                    $validated['purchase_order_status'] ?? null,
                    Auth::id()
                ]);
                
                return DB::getPdo()->lastInsertId();
            }
        }
        
        return null;
    }
}
