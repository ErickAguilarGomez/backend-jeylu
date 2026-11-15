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

    private function cleanupUploadedImages(array $imageUrls, string $assetType = 'product image'): void
    {
        foreach (array_unique($imageUrls) as $imageUrl) {
            try {
                if (!$this->cloudinaryService->delete($imageUrl)) {
                    Log::warning("Failed to clean up a {$assetType} after a save operation.");
                }
            } catch (\Throwable $cleanupError) {
                Log::warning("Failed to clean up a {$assetType} after a save operation.", [
                    'error' => $cleanupError->getMessage(),
                ]);
            }
        }
    }

    private function hasOversizedVariantSku(string $baseSku, array $variants, bool $updating = false): bool
    {
        $generatedSkus = [];

        foreach ($variants as $index => $variant) {
            $rawSize = (string) ($variant['size'] ?? '');
            $sizeClean = preg_replace('/[^A-Z0-9]/', '', strtoupper($rawSize));

            if ($sizeClean === '') {
                $sizeClean = $updating ? 'VAR' . ($index + 1) : 'U';
            }

            $variantSku = $baseSku . '-' . $sizeClean;
            if (in_array($variantSku, $generatedSkus, true)) {
                $variantSku .= '-' . ($index + 1);
            }
            $generatedSkus[] = $variantSku;

            $characters = preg_match_all('/./us', $variantSku, $matches);
            $skuLength = $characters === false ? strlen($variantSku) : $characters;

            if ($skuLength > 100) {
                return true;
            }
        }

        return false;
    }

    private function getStoreIdIfSeller()
    {
        $user = Auth::user();
        
        if (!$user || $user->role_id != 2 || $user->is_helper) {
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
                $increaseAmount = round($originalPrice * ($discountPct / 100), 2);
                $item->price = round($originalPrice + $increaseAmount, 2);
                $item->discounted_price = $item->price;
            } else {
                $item->discounted_price = $originalPrice;
            }

            if (Auth::check() && Auth::user()->is_helper) {
                $item->purchase_price = 0.00;
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

        $result = $this->productRepo->getPaginated($page, $perPage, $search, $storeId, $categoryId, $includeDeleted, true);

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
        $onlyDeleted = $request->query('only_deleted') == 1 || $includeDeleted;
        $result = $this->productRepo->getPaginated($page, $perPage, $search, $storeId ? (int) $storeId : null, $categoryId, false, false, $onlyDeleted);

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

        $images = DB::select(
            'SELECT id, product_id FROM product_images WHERE image_url = ?',
            [$validated['image_url']]
        );

        if (empty($images)) {
            return response()->json([
                'success' => false,
                'message' => 'La imagen ya no existe en la galería del producto.'
            ], 404);
        }

        $productIds = array_values(array_unique(array_map(
            static fn ($image) => (int) $image->product_id,
            $images
        )));

        DB::transaction(function () use ($validated, $productIds) {
            DB::delete("DELETE FROM product_images WHERE image_url = ?", [$validated['image_url']]);

            foreach ($productIds as $productId) {
                $hasPrimary = DB::select(
                    'SELECT id FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1',
                    [$productId]
                );

                if (empty($hasPrimary)) {
                    $nextImage = DB::select(
                        'SELECT id FROM product_images WHERE product_id = ? ORDER BY id ASC LIMIT 1',
                        [$productId]
                    );

                    if (!empty($nextImage)) {
                        DB::update(
                            'UPDATE product_images SET is_primary = 1, updated_at = ? WHERE id = ?',
                            [now(), $nextImage[0]->id]
                        );
                    }
                }
            }
        });

        // Remove the database reference before deleting the remote/local asset.
        // If the database operation fails, the image remains available to the
        // product instead of leaving a gallery row that points to a missing file.
        $storageCleanupPending = false;
        $storageCleanupError = null;
        try {
            $storageCleanupPending = !$this->cloudinaryService->delete($validated['image_url']);
        } catch (\Throwable $e) {
            $storageCleanupPending = true;
            $storageCleanupError = $e->getMessage();
        }

        if ($storageCleanupPending) {
            Log::warning('Product image was removed from the gallery but storage cleanup is pending.', [
                'error' => $storageCleanupError,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $storageCleanupPending
                ? 'Imagen retirada de la galería; la limpieza del almacenamiento quedó pendiente.'
                : 'Imagen eliminada exitosamente del almacenamiento y base de datos.',
            'storage_cleanup_pending' => $storageCleanupPending,
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
            'images' => ['nullable', 'array', 'max:20'],
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

        if ($this->hasOversizedVariantSku($validated['base_sku'], $validated['variants'])) {
            return response()->json([
                'success' => false,
                'message' => 'La combinación del SKU base y la talla genera un SKU de variante demasiado largo. Reduce el SKU base o la talla.'
            ], 422);
        }

        $processedImages = [];
        $uploadedImageUrls = [];

        try {
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $file) {
                    $url = $this->cloudinaryService->upload($file, 'ecommerce_products');
                    $uploadedImageUrls[] = $url;
                    $processedImages[] = [
                        'url' => $url,
                        'is_primary' => count($processedImages) === 0
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->cleanupUploadedImages($uploadedImageUrls);
            throw $e;
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
            
            $sizeClean = $size !== null && $size !== '' ? preg_replace('/[^A-Z0-9]/', '', strtoupper($size)) : 'U';
            $variantSku = $validated['base_sku'] . '-' . $sizeClean;

            $processedVariants[] = [
                'sku' => $variantSku,
                'size' => $size,
                'color' => $color,
                'stocks' => $v['stocks']
            ];
        }

        $purchaseOrderStorageChanges = ['uploaded' => [], 'replaced' => []];
        try {
            $created = DB::transaction(function () use ($request, $validated, $processedImages, $processedVariants, &$purchaseOrderStorageChanges) {
                $purchaseOrderId = $this->resolvePurchaseOrderId($request, $validated, $purchaseOrderStorageChanges);
                $storeId = (int) $validated['store_id'];

                $productData = [
                    'base_sku' => $validated['base_sku'],
                    'category_id' => $validated['category_id'],
                    'purchase_order_id' => $purchaseOrderId,
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'video_url' => $validated['video_url'] ?? null,
                    'price' => $validated['price'],
                    'purchase_price' => (Auth::check() && Auth::user()->is_helper) ? 0.00 : ($validated['purchase_price'] ?? 0.00),
                    'images' => $processedImages,
                    'variants' => $processedVariants
                ];

                return $this->productRepo->create($productData, $storeId);
            });
        } catch (\Throwable $e) {
            $this->cleanupUploadedImages($uploadedImageUrls);
            $this->cleanupUploadedImages($purchaseOrderStorageChanges['uploaded'], 'purchase order file');
            throw $e;
        }

        $this->cleanupUploadedImages($purchaseOrderStorageChanges['replaced'], 'replaced purchase order file');

        return response()->json([
            'success' => true,
            'data' => $created,
            'message' => 'Product created.'
        ], 201);
    }

    public function update(Request $request, string $baseSku)
    {
        $product = $this->productRepo->findBySku($baseSku, null, true);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        $validated = $request->validate([
            'base_sku' => ['nullable', 'string', 'max:100'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array', 'max:20'],
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

        if (!empty($validated['base_sku']) && $validated['base_sku'] !== $baseSku) {
            $existing = DB::select("SELECT id FROM products WHERE base_sku = ? AND id != ?", [$validated['base_sku'], $product->id]);
            if (!empty($existing)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El código SKU ingresado ya pertenece a otro producto.'
                ], 422);
            }
        }

        $targetBaseSku = !empty($validated['base_sku']) ? $validated['base_sku'] : $baseSku;
        if ($this->hasOversizedVariantSku($targetBaseSku, $validated['variants'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'La combinación del SKU base y la talla genera un SKU de variante demasiado largo. Reduce el SKU base o la talla.'
            ], 422);
        }

        // Keep variant IDs scoped to this product and reject duplicate IDs before
        // uploading files or changing any product data.
        $submittedVariantIds = [];
        foreach ($validated['variants'] as $variant) {
            if (isset($variant['id'])) {
                $submittedVariantIds[] = (int) $variant['id'];
            }
        }

        $uniqueVariantIds = array_values(array_unique($submittedVariantIds));
        if (count($uniqueVariantIds) !== count($submittedVariantIds)) {
            return response()->json([
                'success' => false,
                'message' => 'La solicitud contiene variantes duplicadas.'
            ], 422);
        }

        if (!empty($uniqueVariantIds)) {
            $placeholders = implode(',', array_fill(0, count($uniqueVariantIds), '?'));
            $ownedVariants = DB::select(
                "SELECT id FROM product_variants WHERE product_id = ? AND id IN ($placeholders)",
                array_merge([$product->id], $uniqueVariantIds)
            );

            if (count($ownedVariants) !== count($uniqueVariantIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Una o más variantes no pertenecen a este producto. Recarga el producto e inténtalo de nuevo.'
                ], 422);
            }
        }

        // Sales keep a restrictive reference to their original variant. Reject
        // attempts to remove a sold variant before uploading any new images.
        $soldVariants = DB::select(
            'SELECT DISTINCT pv.id
             FROM product_variants pv
             INNER JOIN sale_items si ON si.variant_id = pv.id
             WHERE pv.product_id = ?',
            [$product->id]
        );
        $soldVariantIds = array_map(static fn ($variant) => (int) $variant->id, $soldVariants);
        $existingVariantRows = DB::select('SELECT id FROM product_variants WHERE product_id = ?', [$product->id]);
        $existingVariantIds = array_map(static fn ($variant) => (int) $variant->id, $existingVariantRows);
        $removedSoldVariantIds = array_values(array_intersect(
            $soldVariantIds,
            array_diff($existingVariantIds, $uniqueVariantIds)
        ));

        if (!empty($removedSoldVariantIds)) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar variantes que ya tienen ventas registradas. Conserva esas variantes y pon su stock en 0.'
            ], 422);
        }

        // Upload new image files now, but defer their database rows to the
        // repository transaction together with the product and its variants.
        $processedImages = [];
        $uploadedImageUrls = [];

        try {
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $file) {
                    $url = $this->cloudinaryService->upload($file, 'ecommerce_products');
                    $uploadedImageUrls[] = $url;
                    $processedImages[] = ['url' => $url];
                }
            }
        } catch (\Throwable $e) {
            $this->cleanupUploadedImages($uploadedImageUrls);
            throw $e;
        }

        $processedVariants = [];
        foreach ($validated['variants'] as $index => $v) {
            $size = $v['size'] ?? null;
            $color = $v['color'] ?? null;
            
            $sizeClean = $size !== null && $size !== '' ? preg_replace('/[^A-Z0-9]/', '', strtoupper($size)) : 'U';
            $targetBaseSku = !empty($validated['base_sku']) ? $validated['base_sku'] : $baseSku;
            $variantSku = $targetBaseSku . '-' . $sizeClean;

            $processedVariants[] = [
                'id' => $v['id'] ?? null,
                'sku' => $variantSku,
                'size' => $size,
                'color' => $color,
                'stocks' => $v['stocks']
            ];
        }

        $purchaseOrderStorageChanges = ['uploaded' => [], 'replaced' => []];
        try {
            $updated = DB::transaction(function () use ($request, $validated, $product, $baseSku, $processedImages, $processedVariants, &$purchaseOrderStorageChanges) {
                $purchaseOrderId = $this->resolvePurchaseOrderId($request, $validated, $purchaseOrderStorageChanges);
                // If option is none, we explicitly clean it. If option is not provided, we keep the previous purchase_order_id.
                $updatePayload = [
                    'base_sku' => $validated['base_sku'] ?? $baseSku,
                    'category_id' => $validated['category_id'],
                    'name' => $validated['name'],
                    'price' => $validated['price'],
                    'purchase_price' => (Auth::check() && Auth::user()->is_helper) ? $product->purchase_price : ($validated['purchase_price'] ?? 0.00),
                    'description' => $validated['description'] ?? null,
                    'video_url' => $validated['video_url'] ?? null,
                    'images' => $processedImages,
                    'image_urls' => $validated['image_urls'] ?? [],
                    'variants' => $processedVariants
                ];

                if (isset($validated['purchase_order_option'])) {
                    $updatePayload['purchase_order_id'] = $purchaseOrderId;
                } else {
                    $updatePayload['purchase_order_id'] = $product->purchase_order_id;
                }

                $storeId = (int) $validated['store_id'];

                return $this->productRepo->update($baseSku, $updatePayload, $storeId);
            });
        } catch (\Throwable $e) {
            $this->cleanupUploadedImages($uploadedImageUrls);
            $this->cleanupUploadedImages($purchaseOrderStorageChanges['uploaded'], 'purchase order file');
            throw $e;
        }

        $this->cleanupUploadedImages($purchaseOrderStorageChanges['replaced'], 'replaced purchase order file');

        return response()->json([
            'success' => true,
            'data' => $updated,
            'message' => 'Product updated.'
        ]);
    }

    public function destroy(string $sku)
    {
        $product = $this->productRepo->findBySku($sku, null, true);

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

    private function resolvePurchaseOrderId(Request $request, array $validated, array &$storageChanges)
    {
        $storageChanges['uploaded'] ??= [];
        $storageChanges['replaced'] ??= [];
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
                    $previousFile = DB::select('SELECT file_url FROM purchase_orders WHERE id = ?', [$poId]);
                    if (!empty($previousFile[0]->file_url)) {
                        $storageChanges['replaced'][] = $previousFile[0]->file_url;
                    }

                    $file = $request->file('purchase_order_file');
                    $fileUrl = $this->cloudinaryService->upload($file, 'purchase_orders');
                    $storageChanges['uploaded'][] = $fileUrl;
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
                    $updateParams[] = now();
                    $updateParams[] = $poId;
                    DB::update("
                        UPDATE purchase_orders 
                        SET " . implode(", ", $updateData) . ", updated_at = ?
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
                $storageChanges['uploaded'][] = $fileUrl;
                
                DB::insert("
                    INSERT INTO purchase_orders (order_number, file_url, provider, purchase_date, total_amount, observations, status, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $validated['purchase_order_number'] ?? null,
                    $fileUrl,
                    $validated['purchase_order_provider'] ?? null,
                    $validated['purchase_order_date'] ?? null,
                    $validated['purchase_order_total'] ?? null,
                    $validated['purchase_order_observations'] ?? null,
                    $validated['purchase_order_status'] ?? null,
                    Auth::id(),
                    now(),
                    now()
                ]);
                
                return DB::getPdo()->lastInsertId();
            }
        }
        
        return null;
    }
}
