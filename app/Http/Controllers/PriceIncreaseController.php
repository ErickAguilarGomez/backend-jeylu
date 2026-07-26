<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PriceIncreaseController extends Controller
{
    public function show()
    {
        $row = DB::select("SELECT * FROM general_discounts ORDER BY id ASC LIMIT 1");

        if (!empty($row)) {
            $data = [
                'id' => $row[0]->id,
                'type' => $row[0]->type ?? 'increase',
                'value' => (float)($row[0]->value ?? $row[0]->percentage ?? 0.00),
                'percentage' => (float)($row[0]->value ?? $row[0]->percentage ?? 0.00),
                'is_active' => (bool)$row[0]->is_active
            ];
        } else {
            $data = [
                'id' => null,
                'type' => 'increase',
                'value' => 0.00,
                'percentage' => 0.00,
                'is_active' => false
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function upsert(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:increase,discount'],
            'value' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean']
        ], [
            'type.required' => 'El tipo de ajuste (Incremento o Descuento) es obligatorio.',
            'type.in' => 'El tipo de ajuste debe ser Incremento o Descuento.',
            'value.required' => 'El valor del ajuste es obligatorio.',
            'value.min' => 'El valor del ajuste no puede ser negativo.',
            'value.max' => 'El valor del ajuste no puede superar el 100%.'
        ]);

        $type = $validated['type'];
        $value = (float) $validated['value'];
        $isActive = isset($validated['is_active']) ? (bool)$validated['is_active'] : true;
        $userId = Auth::id();

        $existing = DB::select("SELECT id FROM general_discounts ORDER BY id ASC LIMIT 1");

        if (!empty($existing)) {
            $id = $existing[0]->id;
            DB::update("
                UPDATE general_discounts 
                SET type = ?, value = ?, percentage = ?, is_active = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ", [$type, $value, $value, $isActive ? 1 : 0, $userId, $id]);
        } else {
            DB::insert("
                INSERT INTO general_discounts (type, value, percentage, is_active, created_by, updated_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [$type, $value, $value, $isActive ? 1 : 0, $userId, $userId]);
        }

        return $this->show();
    }

    public function toggle()
    {
        $existing = DB::select("SELECT * FROM general_discounts ORDER BY id ASC LIMIT 1");

        if (empty($existing)) {
            return response()->json([
                'success' => false,
                'message' => 'No hay un ajuste general configurado.'
            ], 404);
        }

        $newActive = $existing[0]->is_active ? 0 : 1;
        $userId = Auth::id();

        DB::update("
            UPDATE general_discounts 
            SET is_active = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ", [$newActive, $userId, $existing[0]->id]);

        return $this->show();
    }
}
