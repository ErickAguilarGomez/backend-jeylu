<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = Auth::user();

            $role = DB::select("SELECT name FROM roles WHERE id = ?", [$user->role_id])[0]->name ?? 'Cashier';

            $primaryStore = null;
            $storeUser = DB::select("
                SELECT s.id, s.name, s.address 
                FROM store_user su
                INNER JOIN stores s ON su.store_id = s.id
                WHERE su.user_id = ? AND su.is_primary = 1
                LIMIT 1
            ", [$user->id]);
            if (!empty($storeUser)) {
                $primaryStore = [
                    'id' => $storeUser[0]->id,
                    'name' => $storeUser[0]->name,
                    'address' => $storeUser[0]->address
                ];
            }

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_id' => $user->role_id,
                    'role_name' => $role,
                    'primary_store' => $primaryStore
                ],
                'message' => 'Sesión iniciada con éxito.'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Las credenciales proporcionadas no coinciden con nuestros registros.'
        ], 401);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada exitosamente.'
        ]);
    }

    public function user(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $user = Auth::user();
        $role = DB::select("SELECT name FROM roles WHERE id = ?", [$user->role_id])[0]->name ?? 'Cashier';

        $primaryStore = null;
        $storeUser = DB::select("
            SELECT s.id, s.name, s.address 
            FROM store_user su
            INNER JOIN stores s ON su.store_id = s.id
            WHERE su.user_id = ? AND su.is_primary = 1
            LIMIT 1
        ", [$user->id]);
        if (!empty($storeUser)) {
            $primaryStore = [
                'id' => $storeUser[0]->id,
                'name' => $storeUser[0]->name,
                'address' => $storeUser[0]->address
            ];
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'role_name' => $role,
                'primary_store' => $primaryStore
            ]
        ]);
    }
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ], [
            'email.unique' => 'El correo electrónico ya está registrado.'
        ]);

        $roleId = 3; // Siempre registrar como Usuario (Cliente) por defecto
        $hashed = Hash::make($validated['password']);

        DB::insert("
            INSERT INTO users (role_id, name, email, password, created_at, updated_at) 
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ", [$roleId, $validated['name'], $validated['email'], $hashed]);

        return response()->json([
            'success' => true,
            'message' => 'Usuario registrado exitosamente.'
        ]);
    }
}
