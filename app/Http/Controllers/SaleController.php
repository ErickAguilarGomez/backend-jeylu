<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SaleService;
use App\Repositories\SaleRepository;
use Illuminate\Support\Facades\Auth;
use Exception;

class SaleController extends Controller
{
    protected $saleService;
    protected $saleRepo;

    public function __construct(SaleService $saleService, SaleRepository $saleRepo)
    {
        $this->saleService = $saleService;
        $this->saleRepo = $saleRepo;
    }

    /**
     * Listado del historial de ventas (paginado).
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $search = $request->query('search', '');

        $sellerId = null;
        if ($user->role_id == 2) {
            $sellerId = $user->id; // Vendedores solo ven sus ventas
        } elseif ($user->role_id == 1) {
            if ($request->has('seller_id') && $request->query('seller_id') !== '') {
                $sellerId = (int) $request->query('seller_id');
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $storeId = $request->query('store_id') ? (int) $request->query('store_id') : null;

        $result = $this->saleRepo->getPaginated($page, $perPage, $storeId, $search, $sellerId, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'],
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page']
            ]
        ]);
    }

    /**
     * Obtener estadísticas de ventas generales o filtradas.
     */
    public function stats(Request $request)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role_id, [1, 2])) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $sellerId = null;
        if ($user->role_id == 2) {
            $sellerId = $user->id;
        } elseif ($user->role_id == 1) {
            if ($request->has('seller_id') && $request->query('seller_id') !== '') {
                $sellerId = (int) $request->query('seller_id');
            }
        }

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $storeId = $request->query('store_id') ? (int) $request->query('store_id') : null;

        $stats = $this->saleRepo->getStats($storeId, $sellerId, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'stats' => [
                'total_amount' => (float) $stats->total_amount,
                'total_sales' => (int) $stats->total_sales
            ]
        ]);
    }

    /**
     * Registrar una nueva venta desde el POS / Carrito.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role_id, [1, 2])) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado. Solo administradores o vendedores autorizados pueden registrar ventas POS.'
            ], 403);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id']
        ]);

        $userId = $user ? $user->id : 1;

        $storeId = 1;

        if ($user && $user->role_id == 1 && !empty($validated['store_id'])) {
            $storeId = $validated['store_id'];
        } else {
            $storeUser = \Illuminate\Support\Facades\DB::select("
                SELECT store_id FROM store_user 
                WHERE user_id = ? AND is_primary = 1 
                LIMIT 1
            ", [$userId]);

            if (!empty($storeUser)) {
                $storeId = $storeUser[0]->store_id;
            }
        }

        try {
            $result = $this->saleService->processSale(
                $userId,
                $storeId,
                $validated['items'],
                $validated['customer_id'] ?? null,
                $validated['customer_name'] ?? null
            );
            return response()->json($result, 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
