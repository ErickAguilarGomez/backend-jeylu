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
                'total_commission' => (float) ($stats->total_commission ?? 0.00),
                'total_sales' => (int) $stats->total_sales
            ]
        ]);
    }

 
    public function commissionsReport(Request $request)
    {
        $user = Auth::user();
        if (!$user || $user->role_id != 1) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $sellerId = $request->query('seller_id') ? (int) $request->query('seller_id') : null;
        $status = $request->query('status');

        $sql = "
            SELECT 
                u.id as seller_id,
                u.name as seller_name,
                COUNT(s.id) as total_sales,
                COALESCE(SUM(s.total), 0) as total_sold,
                COALESCE(SUM(s.commission_amount), 0) as total_commission
            FROM users u
            LEFT JOIN sales s ON u.id = s.seller_id
        ";

        $joinConditions = [];
        $joinParams = [];

        if ($startDate) {
            $joinConditions[] = "s.created_at >= ?";
            $joinParams[] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $joinConditions[] = "s.created_at <= ?";
            $joinParams[] = $endDate . ' 23:59:59';
        }
        if ($status) {
            $joinConditions[] = "s.status = ?";
            $joinParams[] = $status;
        } else {
            $joinConditions[] = "s.status IN ('COMPLETED', 'EXCHANGED')";
        }

        if (!empty($joinConditions)) {
            $sql .= " AND " . implode(" AND ", $joinConditions);
        }

        $sql .= " WHERE u.role_id = 2";
        $whereParams = [];
        if ($sellerId) {
            $sql .= " AND u.id = ?";
            $whereParams[] = $sellerId;
        }

        $sql .= " GROUP BY u.id, u.name ORDER BY total_commission DESC";

        $params = array_merge($joinParams, $whereParams);
        $data = \Illuminate\Support\Facades\DB::select($sql, $params);

        // Convert values to correct numeric types
        foreach ($data as $item) {
            $item->total_sales = (int) $item->total_sales;
            $item->total_sold = (float) $item->total_sold;
            $item->total_commission = (float) $item->total_commission;
        }

        return response()->json([
            'success' => true,
            'data' => $data
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
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
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

    public function update(Request $request, int $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $sale = \Illuminate\Support\Facades\DB::select("SELECT * FROM sales WHERE id = ? LIMIT 1", [$id]);
        if (empty($sale)) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found.'
            ], 404);
        }
        $sale = $sale[0];

        if ($user->role_id != 1 && ($user->role_id != 2 || $sale->seller_id != $user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado. No tienes permisos para editar esta venta.'
            ], 403);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'in:COMPLETED,EXCHANGED,REFUNDED,CANCELLED'],
            'customer_name' => ['nullable', 'string', 'max:255']
        ]);

        try {
            $result = $this->saleService->updateSale(
                $id,
                $validated['items'],
                $validated['status'],
                $validated['customer_name'] ?? null,
                $user->id
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
