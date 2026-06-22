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
        
        if (!$user || (int)$user->role_id !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado. Se requieren privilegios de Administrador para realizar esta acción.'
            ], 403);
        }

        return $next($request);
    }
}
