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
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $search = $request->query('search', '');

        $result = $this->saleRepo->getPaginated($page, $perPage, null, $search);

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
