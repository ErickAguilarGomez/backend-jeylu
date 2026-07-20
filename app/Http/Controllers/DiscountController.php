<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DiscountController extends Controller
{
    public function show()
    {
        $discount = DB::select("SELECT * FROM general_discounts ORDER BY id ASC LIMIT 1");

        $data = !empty($discount) ? $discount[0] : [
            'id' => null,
            'percentage' => 0.00,
            'is_active' => false
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function upsert(Request $request)
    {
        $validated = $request->validate([
            'percentage' => ['required', 'numeric', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean']
        ], [
            'percentage.required' => 'El porcentaje de descuento es obligatorio.',
            'percentage.min' => 'El descuento debe ser de al menos 1%.',
            'percentage.max' => 'El descuento no puede superar el 100%.'
        ]);

        $percentage = (float) $validated['percentage'];
        $isActive = isset($validated['is_active']) ? (bool)$validated['is_active'] : true;
        $userId = Auth::id();

        $existing = DB::select("SELECT id FROM general_discounts ORDER BY id ASC LIMIT 1");

        if (!empty($existing)) {
            $id = $existing[0]->id;
            DB::update("
                UPDATE general_discounts 
                SET percentage = ?, is_active = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ", [$percentage, $isActive ? 1 : 0, $userId, $id]);
        } else {
            DB::insert("
                INSERT INTO general_discounts (percentage, is_active, created_by, updated_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ", [$percentage, $isActive ? 1 : 0, $userId, $userId]);
        }

        $discount = DB::select("SELECT * FROM general_discounts ORDER BY id ASC LIMIT 1")[0];

        return response()->json([
            'success' => true,
            'message' => 'Descuento general guardado exitosamente.',
            'data' => $discount
        ]);
    }

    public function toggle()
    {
        $existing = DB::select("SELECT * FROM general_discounts ORDER BY id ASC LIMIT 1");

        if (empty($existing)) {
            return response()->json([
                'success' => false,
                'message' => 'No hay un descuento general configurado.'
            ], 404);
        }

        $newActive = $existing[0]->is_active ? 0 : 1;
        $userId = Auth::id();

        DB::update("
            UPDATE general_discounts 
            SET is_active = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ", [$newActive, $userId, $existing[0]->id]);

        $updated = DB::select("SELECT * FROM general_discounts WHERE id = ?", [$existing[0]->id])[0];

        return response()->json([
            'success' => true,
            'message' => $updated->is_active ? 'Descuento general activado.' : 'Descuento general desactivado.',
            'data' => $updated
        ]);
    }
}
