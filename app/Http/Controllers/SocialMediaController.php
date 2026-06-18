<?php

namespace App\Http\Controllers;

use App\Models\SocialMediaSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;

class SocialMediaController extends Controller
{
    /**
     * Get active social media settings (Public)
     */
    public function index()
    {
        $settings = SocialMediaSetting::where('active', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Get all social media settings (Admin)
     */
    public function adminIndex()
    {
        $settings = SocialMediaSetting::orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    /**
     * Create new social media config
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50', 'unique:social_media_settings,type'],
            'url' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'default_message' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer']
        ]);

        try {
            $userId = Auth::id();
            
            // Get max sort_order
            $maxSort = SocialMediaSetting::max('sort_order') ?? 0;

            $setting = SocialMediaSetting::create([
                'type' => strtolower($validated['type']),
                'url' => $validated['url'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'default_message' => $validated['default_message'] ?? null,
                'icon' => $validated['icon'] ?? strtolower($validated['type']),
                'active' => isset($validated['active']) ? (bool)$validated['active'] : true,
                'sort_order' => isset($validated['sort_order']) ? (int)$validated['sort_order'] : ($maxSort + 1),
                'created_by' => $userId,
                'updated_by' => $userId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuración de red social creada exitosamente.',
                'data' => $setting
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar la red social: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update social media config
     */
    public function update(Request $request, int $id)
    {
        $setting = SocialMediaSetting::find($id);
        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Red social no encontrada.'
            ], 404);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50', 'unique:social_media_settings,type,' . $id],
            'url' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'default_message' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer']
        ]);

        try {
            $userId = Auth::id();

            $setting->update([
                'type' => strtolower($validated['type']),
                'url' => $validated['url'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'default_message' => $validated['default_message'] ?? null,
                'icon' => $validated['icon'] ?? strtolower($validated['type']),
                'active' => (bool)$validated['active'],
                'sort_order' => isset($validated['sort_order']) ? (int)$validated['sort_order'] : $setting->sort_order,
                'updated_by' => $userId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Configuración de red social actualizada exitosamente.',
                'data' => $setting
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la red social: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete social media config
     */
    public function destroy(int $id)
    {
        $setting = SocialMediaSetting::find($id);
        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Red social no encontrada.'
            ], 404);
        }

        try {
            $setting->delete();
            return response()->json([
                'success' => true,
                'message' => 'Red social eliminada exitosamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la red social: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sort social media configs
     */
    public function sort(Request $request)
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:social_media_settings,id']
        ]);

        try {
            $userId = Auth::id();
            foreach ($validated['order'] as $index => $id) {
                SocialMediaSetting::where('id', $id)->update([
                    'sort_order' => $index + 1,
                    'updated_by' => $userId
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden de redes sociales actualizado exitosamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al ordenar las redes sociales: ' . $e->getMessage()
            ], 500);
        }
    }
}
