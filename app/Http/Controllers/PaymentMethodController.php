<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PaymentMethodController extends Controller
{
    private function seedDefaultMethodsIfEmpty()
    {
        $count = DB::select("SELECT COUNT(*) as total FROM payment_methods")[0]->total;
        if ($count == 0) {
            $defaultMethods = [
                ['name' => 'Efectivo', 'description' => 'Pago en efectivo en tienda'],
                ['name' => 'Yape / Plin', 'description' => 'Billetera digital Yape o Plin'],
                ['name' => 'Transferencia Bancaria', 'description' => 'Transferencia o depósito bancario'],
                ['name' => 'Tarjeta de Débito / Crédito', 'description' => 'Pago con POS o tarjeta física/online']
            ];

            foreach ($defaultMethods as $method) {
                DB::insert("
                    INSERT INTO payment_methods (name, description, is_active, created_at, updated_at)
                    VALUES (?, ?, 1, NOW(), NOW())
                ", [$method['name'], $method['description']]);
            }
        }
    }

    public function index(Request $request)
    {
        $this->seedDefaultMethodsIfEmpty();

        $activeOnly = $request->query('active_only') == 1;

        $sql = "SELECT id, name, description, is_active, created_at, updated_at FROM payment_methods WHERE 1=1";
        $params = [];

        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }

        $sql .= " ORDER BY id ASC";

        $data = DB::select($sql, $params);

        foreach ($data as $item) {
            $item->is_active = (bool) $item->is_active;
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:payment_methods,name'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean']
        ], [
            'name.required' => 'El nombre de la forma de pago es obligatorio.',
            'name.unique' => 'Ya existe una forma de pago registrada con este nombre.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.'
        ]);

        $userId = Auth::id();
        $isActive = isset($validated['is_active']) ? (bool)$validated['is_active'] : true;

        DB::insert("
            INSERT INTO payment_methods (name, description, is_active, created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ", [
            $validated['name'],
            $validated['description'] ?? null,
            $isActive ? 1 : 0,
            $userId,
            $userId
        ]);

        $id = DB::getPdo()->lastInsertId();
        $method = DB::select("SELECT * FROM payment_methods WHERE id = ?", [$id])[0];
        $method->is_active = (bool) $method->is_active;

        return response()->json([
            'success' => true,
            'message' => 'Forma de pago registrada exitosamente.',
            'data' => $method
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:payment_methods,name,' . $id],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean']
        ], [
            'name.required' => 'El nombre de la forma de pago es obligatorio.',
            'name.unique' => 'Ya existe una forma de pago registrada con este nombre.'
        ]);

        $userId = Auth::id();
        $isActive = isset($validated['is_active']) ? (bool)$validated['is_active'] : true;

        DB::update("
            UPDATE payment_methods 
            SET name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ", [
            $validated['name'],
            $validated['description'] ?? null,
            $isActive ? 1 : 0,
            $userId,
            $id
        ]);

        $method = DB::select("SELECT * FROM payment_methods WHERE id = ?", [$id])[0];
        $method->is_active = (bool) $method->is_active;

        return response()->json([
            'success' => true,
            'message' => 'Forma de pago actualizada exitosamente.',
            'data' => $method
        ]);
    }

    public function toggle(int $id)
    {
        $existing = DB::select("SELECT * FROM payment_methods WHERE id = ? LIMIT 1", [$id]);

        if (empty($existing)) {
            return response()->json([
                'success' => false,
                'message' => 'Forma de pago no encontrada.'
            ], 404);
        }

        $newActive = $existing[0]->is_active ? 0 : 1;
        $userId = Auth::id();

        DB::update("
            UPDATE payment_methods 
            SET is_active = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ", [$newActive, $userId, $id]);

        $updated = DB::select("SELECT * FROM payment_methods WHERE id = ?", [$id])[0];
        $updated->is_active = (bool) $updated->is_active;

        return response()->json([
            'success' => true,
            'message' => $updated->is_active ? 'Forma de pago activada.' : 'Forma de pago desactivada.',
            'data' => $updated
        ]);
    }
}
