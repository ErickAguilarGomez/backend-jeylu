<?php

namespace App\Http\Controllers;

use App\Models\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;

class WhatsappNumberController extends Controller
{
    /**
     * Get active whatsapp numbers (Public)
     */
    public function index()
    {
        $numbers = WhatsappNumber::where('is_active', true)
            ->orderBy('display_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $numbers
        ]);
    }

    /**
     * Get all whatsapp numbers (Admin)
     */
    public function adminIndex()
    {
        $numbers = WhatsappNumber::orderBy('display_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $numbers
        ]);
    }

    /**
     * Create new whatsapp number
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'alias' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:50', 'unique:whatsapp_numbers,phone', 'regex:/^\+?[0-9]+$/'],
            'is_active' => ['required', 'boolean'],
            'display_order' => ['nullable', 'integer']
        ], [
            'phone.required' => 'El número de teléfono es obligatorio.',
            'phone.unique' => 'Este número de teléfono ya está registrado.',
            'phone.regex' => 'El número de teléfono debe contener únicamente dígitos y puede comenzar con +.'
        ]);

        try {
            $userId = Auth::id();
            $maxOrder = WhatsappNumber::max('display_order') ?? 0;

            $number = WhatsappNumber::create([
                'alias' => $validated['alias'] ?? null,
                'phone' => $validated['phone'],
                'is_active' => (bool)$validated['is_active'],
                'display_order' => isset($validated['display_order']) ? (int)$validated['display_order'] : ($maxOrder + 1),
                'created_by' => $userId,
                'updated_by' => $userId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Número de WhatsApp registrado exitosamente.',
                'data' => $number
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el número: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update whatsapp number
     */
    public function update(Request $request, int $id)
    {
        $number = WhatsappNumber::find($id);
        if (!$number) {
            return response()->json([
                'success' => false,
                'message' => 'Número de WhatsApp no encontrado.'
            ], 404);
        }

        $validated = $request->validate([
            'alias' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^\+?[0-9]+$/', 'unique:whatsapp_numbers,phone,' . $id],
            'is_active' => ['required', 'boolean'],
            'display_order' => ['nullable', 'integer']
        ], [
            'phone.required' => 'El número de teléfono es obligatorio.',
            'phone.unique' => 'Este número de teléfono ya está registrado.',
            'phone.regex' => 'El número de teléfono debe contener únicamente dígitos y puede comenzar con +.'
        ]);

        try {
            $userId = Auth::id();

            $number->update([
                'alias' => $validated['alias'] ?? null,
                'phone' => $validated['phone'],
                'is_active' => (bool)$validated['is_active'],
                'display_order' => isset($validated['display_order']) ? (int)$validated['display_order'] : $number->display_order,
                'updated_by' => $userId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Número de WhatsApp actualizado exitosamente.',
                'data' => $number
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el número: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete whatsapp number
     */
    public function destroy(int $id)
    {
        $number = WhatsappNumber::find($id);
        if (!$number) {
            return response()->json([
                'success' => false,
                'message' => 'Número de WhatsApp no encontrado.'
            ], 404);
        }

        try {
            $number->delete();
            return response()->json([
                'success' => true,
                'message' => 'Número de WhatsApp eliminado exitosamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el número: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleActive(int $id)
    {
        $number = WhatsappNumber::find($id);
        if (!$number) {
            return response()->json([
                'success' => false,
                'message' => 'Número de WhatsApp no encontrado.'
            ], 404);
        }

        try {
            $number->update([
                'is_active' => !$number->is_active,
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado exitosamente.',
                'data' => $number
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder display order
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:whatsapp_numbers,id']
        ]);

        try {
            $userId = Auth::id();
            foreach ($validated['order'] as $index => $id) {
                WhatsappNumber::where('id', $id)->update([
                    'display_order' => $index + 1,
                    'updated_by' => $userId
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden de números actualizado exitosamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reordenar los números: ' . $e->getMessage()
            ], 500);
        }
    }
}
