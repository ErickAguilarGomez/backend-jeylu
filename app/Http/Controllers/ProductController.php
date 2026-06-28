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
        foreach ($products as $item) {
            $item->is_available = (bool) $item->is_available;
            if (!$keepStock && property_exists($item, 'total_stock')) {
                unset($item->total_stock);
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

        return response()->json([
            'success' => true,
            'data' => $product
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
            'name' => ['required', 'string', 'max:255', 'unique:products,name'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'file', 'image', 'max:5120'],
            'image_urls' => ['nullable', 'array'],
            'image_urls.*' => ['nullable', 'string'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.size' => ['required', 'string', 'max:50'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.stocks' => ['required', 'array'],
            'variants.*.stocks.*' => ['required', 'integer', 'min:0']
        ], [
            'name.unique' => 'Ya existe un producto con este nombre.',
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
            $size = $v['size'];
            $color = $v['color'] ?? null;
            
            $sizeClean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $size));
            $variantSku = $validated['base_sku'] . '-' . $sizeClean;

            $processedVariants[] = [
                'sku' => $variantSku,
                'size' => $size,
                'color' => $color,
                'stocks' => $v['stocks']
            ];
        }

        $storeId = (int) $validated['store_id'];

        $productData = [
            'base_sku' => $validated['base_sku'],
            'category_id' => $validated['category_id'],
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
            'name' => ['required', 'string', 'max:255', 'unique:products,name,' . $product->id],
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
            'variants.*.size' => ['required', 'string', 'max:50'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.stocks' => ['required', 'array'],
            'variants.*.stocks.*' => ['required', 'integer', 'min:0']
        ], [
            'name.unique' => 'Ya existe un producto con este nombre.',
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
            $size = $v['size'];
            $color = $v['color'] ?? null;
            
            $sizeClean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $size));
            $variantSku = $baseSku . '-' . $sizeClean;

            $processedVariants[] = [
                'id' => $v['id'] ?? null,
                'sku' => $variantSku,
                'size' => $size,
                'color' => $color,
                'stocks' => $v['stocks']
            ];
        }

        $storeId = (int) $validated['store_id'];

        $updated = $this->productRepo->update($baseSku, [
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'price' => $validated['price'],
            'purchase_price' => $validated['purchase_price'] ?? 0.00,
            'description' => $validated['description'] ?? null,
            'video_url' => $validated['video_url'] ?? null,
            'variants' => $processedVariants
        ], $storeId);

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
}
