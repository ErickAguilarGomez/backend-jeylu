<?php

namespace App\Http\Controllers;

use App\Repositories\BannerRepository;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Exception;

class BannerController extends Controller
{
    protected $bannerRepo;
    protected $cloudinaryService;

    public function __construct(BannerRepository $bannerRepo, CloudinaryService $cloudinaryService)
    {
        $this->bannerRepo = $bannerRepo;
        $this->cloudinaryService = $cloudinaryService;
    }

    public function index()
    {
        $banners = $this->bannerRepo->getAll();
        return response()->json([
            'success' => true,
            'banners' => $banners
        ]);
    }

    public function activeList()
    {
        $banners = $this->bannerRepo->getActive();
        return response()->json([
            'success' => true,
            'banners' => $banners
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120'], // max 5MB
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'unique:banners,sort_order']
        ], [
            'sort_order.required' => 'El orden de visualización es obligatorio.',
            'sort_order.integer' => 'El orden debe ser un número entero.',
            'sort_order.min' => 'El orden no puede ser un número negativo.',
            'sort_order.unique' => 'Ya existe un banner con este orden de visualización.'
        ]);

        try {
            $imageUrl = $this->cloudinaryService->upload($request->file('image'), 'banners');

            $banner = $this->bannerRepo->create([
                'image_url' => $imageUrl,
                'is_active' => $validated['is_active'] ?? 1,
                'sort_order' => $validated['sort_order'] ?? 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Banner creado exitosamente.',
                'banner' => $banner
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear banner: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $banner = $this->bannerRepo->findById($id);
        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner no encontrado.'
            ], 404);
        }

        $validated = $request->validate([
            'image' => ['nullable', 'file', 'image', 'max:5120'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'unique:banners,sort_order,' . $id]
        ], [
            'sort_order.required' => 'El orden de visualización es obligatorio.',
            'sort_order.integer' => 'El orden debe ser un número entero.',
            'sort_order.min' => 'El orden no puede ser un número negativo.',
            'sort_order.unique' => 'Ya existe un banner con este orden de visualización.'
        ]);

        try {
            $data = [];
            if (isset($validated['is_active'])) {
                $data['is_active'] = $validated['is_active'];
            }
            if (isset($validated['sort_order'])) {
                $data['sort_order'] = $validated['sort_order'];
            }

            if ($request->hasFile('image')) {
                // Delete previous image first
                if (!empty($banner->image_url)) {
                    $this->cloudinaryService->delete($banner->image_url);
                }
                $data['image_url'] = $this->cloudinaryService->upload($request->file('image'), 'banners');
            }

            $updated = $this->bannerRepo->update($id, $data);

            return response()->json([
                'success' => true,
                'message' => 'Banner actualizado exitosamente.',
                'banner' => $updated
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar banner: ' . $e->getMessage()
            ], 500);
        }
    }

    public function toggleActive(int $id)
    {
        $banner = $this->bannerRepo->findById($id);
        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner no encontrado.'
            ], 404);
        }

        $newStatus = $banner->is_active ? 0 : 1;
        $updated = $this->bannerRepo->update($id, ['is_active' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => 'Estado del banner actualizado exitosamente.',
            'banner' => $updated
        ]);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'integer']
        ]);

        try {
            $this->bannerRepo->updateSortOrder($validated['ids']);
            return response()->json([
                'success' => true,
                'message' => 'Orden de banners actualizado exitosamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al reordenar banners: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        $banner = $this->bannerRepo->findById($id);
        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Banner no encontrado.'
            ], 404);
        }

        try {
            if (!empty($banner->image_url)) {
                $this->cloudinaryService->delete($banner->image_url);
            }
            $this->bannerRepo->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Banner eliminado exitosamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar banner: ' . $e->getMessage()
            ], 500);
        }
    }
}
