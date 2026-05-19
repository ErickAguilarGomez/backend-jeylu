<?php

namespace App\Http\Controllers;

use App\Repositories\StoreRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StoreController extends Controller
{
    protected $storeRepo;

    public function __construct(StoreRepository $storeRepo)
    {
        $this->storeRepo = $storeRepo;
    }

    public function index(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $search = $request->query('search', '');

        if ($request->has('all')) {
            $stores = $this->storeRepo->getAll();
            return response()->json([
                'success' => true,
                'stores' => $stores
            ]);
        }

        $result = $this->storeRepo->getPaginated($page, $perPage, $search);

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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:stores',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:50'
        ]);

        $store = $this->storeRepo->create($validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Tienda creada exitosamente.',
            'store' => $store
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'phone' => 'nullable|string|max:50'
        ]);

        $this->storeRepo->update($id, $validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Tienda actualizada exitosamente.'
        ]);
    }

    public function destroy(int $id)
    {
        try {
            $this->storeRepo->delete($id);
            return response()->json([
                'success' => true,
                'message' => 'Tienda eliminada exitosamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar la tienda porque tiene registros o inventarios asociados.'
            ], 400);
        }
    }

    public function getEmployees(int $id)
    {
        $employees = $this->storeRepo->getStoreUsers($id);
        return response()->json([
            'success' => true,
            'employees' => $employees
        ]);
    }

    public function assignEmployee(Request $request, int $id)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'is_primary' => 'boolean'
        ]);

        $this->storeRepo->assignUser($id, $validated['user_id'], $validated['is_primary'] ?? true, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Empleado asignado a la tienda correctamente.'
        ]);
    }

    public function unassignEmployee(int $id, int $userId)
    {
        $this->storeRepo->unassignUser($id, $userId);
        return response()->json([
            'success' => true,
            'message' => 'Empleado desasignado correctamente.'
        ]);
    }
}
