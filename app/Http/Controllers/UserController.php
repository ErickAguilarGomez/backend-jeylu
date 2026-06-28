<?php

namespace App\Http\Controllers;

use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    protected $userRepo;

    public function __construct(UserRepository $userRepo)
    {
        $this->userRepo = $userRepo;
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role_id, [1, 2])) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado. No tienes autorización para realizar esta acción.'
            ], 403);
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $search = $request->query('search', '');

        if ($request->has('all')) {
            $users = $this->userRepo->getAll();
            
            $roleId = $request->has('role_id') ? (int) $request->query('role_id') : 3;
            $unassignedOnly = $request->query('unassigned') == 1;

            $filtered = array_filter($users, function($u) use ($roleId, $unassignedOnly) {
                if ((int)$u->role_id !== $roleId) {
                    return false;
                }
                if ($unassignedOnly) {
                    return empty($u->store_id);
                }
                return true;
            });

            return response()->json([
                'success' => true,
                'users' => array_values($filtered)
            ]);
        }

        $result = $this->userRepo->getPaginated($page, $perPage, $search);

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
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'role_id' => 'required|integer|exists:roles,id'
        ];

        if ($request->input('role_id') == 2) {
            $rules['store_id'] = 'required|integer|exists:stores,id';
        } else {
            $rules['store_id'] = 'nullable|integer|exists:stores,id';
        }

        $validated = $request->validate($rules, [
            'store_id.required' => 'La sucursal es obligatoria para usuarios con el rol de Cajero.',
            'store_id.exists' => 'La sucursal seleccionada no es válida.'
        ]);

        $user = $this->userRepo->create($validated, Auth::id());

        if (!empty($validated['store_id'])) {
            $storeRepo = app(\App\Repositories\StoreRepository::class);
            $storeRepo->assignUser($validated['store_id'], $user->id, true, Auth::id());
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario registrado correctamente.',
            'user' => $user
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $id,
            'password' => 'nullable|string|min:6',
            'role_id' => 'required|integer|exists:roles,id'
        ];

        if ($request->input('role_id') == 2) {
            $rules['store_id'] = 'required|integer|exists:stores,id';
        } else {
            $rules['store_id'] = 'nullable|integer|exists:stores,id';
        }

        $validated = $request->validate($rules, [
            'store_id.required' => 'La sucursal es obligatoria para usuarios con el rol de Cajero.',
            'store_id.exists' => 'La sucursal seleccionada no es válida.'
        ]);

        $this->userRepo->update($id, $validated, Auth::id());

        if (!empty($validated['store_id'])) {
            $storeRepo = app(\App\Repositories\StoreRepository::class);
            $storeRepo->assignUser($validated['store_id'], $id, true, Auth::id());
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado exitosamente.'
        ]);
    }

    public function destroy(int $id)
    {
        if ($id === 1 || $id === Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar al administrador principal o tu propia cuenta.'
            ], 403);
        }

        $this->userRepo->delete($id);

        return response()->json([
            'success' => true,
            'message' => 'Usuario eliminado exitosamente.'
        ]);
    }

    public function roles()
    {
        $roles = $this->userRepo->getRoles();
        return response()->json([
            'success' => true,
            'roles' => $roles
        ]);
    }

    public function getSellersCommissions(Request $request)
    {
        $users = \Illuminate\Support\Facades\DB::select("
            SELECT u.id, u.name, u.email, u.commission_percentage,
                   s.name as store_name
            FROM users u
            LEFT JOIN store_user su ON u.id = su.user_id AND su.is_primary = 1
            LEFT JOIN stores s ON su.store_id = s.id
            WHERE u.role_id = 2
            ORDER BY u.name ASC
        ");

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    public function updateSellerCommission(Request $request, int $id)
    {
        $validated = $request->validate([
            'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100']
        ], [
            'commission_percentage.required' => 'El porcentaje de comisión es obligatorio.',
            'commission_percentage.numeric' => 'El porcentaje debe ser un valor numérico.',
            'commission_percentage.min' => 'El porcentaje no puede ser menor a 0%.',
            'commission_percentage.max' => 'El porcentaje no puede ser mayor a 100%.'
        ]);

        $user = \Illuminate\Support\Facades\DB::select("SELECT id, role_id FROM users WHERE id = ? LIMIT 1", [$id]);
        if (empty($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado.'
            ], 404);
        }

        if ($user[0]->role_id != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se puede configurar la comisión para usuarios con rol de Vendedor.'
            ], 400);
        }

        \Illuminate\Support\Facades\DB::update("
            UPDATE users SET commission_percentage = ? WHERE id = ?
        ", [(float) $validated['commission_percentage'], $id]);

        return response()->json([
            'success' => true,
            'message' => 'Comisión de vendedor actualizada exitosamente.'
        ]);
    }
}
