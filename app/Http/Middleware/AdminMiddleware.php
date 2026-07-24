<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Si el usuario es Vendedor Ayudante (is_helper)
        if ($user->is_helper) {
            $path = $request->path();
            // Los ayudantes SOLO pueden acceder a productos, categorías y sucursales
            if (str_contains($path, 'products') || str_contains($path, 'categories') || str_contains($path, 'stores')) {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Acceso restringido para el rol de Vendedor Ayudante.'
            ], 403);
        }

        // Verificación estándar para administradores
        if ((int)$user->role_id !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado. Se requieren privilegios de Administrador para realizar esta acción.'
            ], 403);
        }

        return $next($request);
    }
}
