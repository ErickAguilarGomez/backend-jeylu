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
            // Only return customers (role_id = 3) to prevent selecting admins/sellers
            $customers = array_values(array_filter($users, function($u) {
                return (int)$u->role_id === 3;
            }));
            return response()->json([
                'success' => true,
                'users' => $customers
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
}
