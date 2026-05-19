<?php

namespace App\Http\Controllers;

use App\Repositories\CategoryRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    protected $categoryRepo;

    public function __construct(CategoryRepository $categoryRepo)
    {
        $this->categoryRepo = $categoryRepo;
    }

    public function index(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);
        $search = $request->query('search', '');

        if ($request->has('all')) {
            $categories = $this->categoryRepo->getAll();
            return response()->json([
                'success' => true,
                'categories' => $categories
            ]);
        }

        $result = $this->categoryRepo->getPaginated($page, $perPage, $search);

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
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string|max:500'
        ]);

        $category = $this->categoryRepo->create($validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Categoría creada exitosamente.',
            'category' => $category
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500'
        ]);

        $this->categoryRepo->update($id, $validated, Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Categoría actualizada exitosamente.'
        ]);
    }

    public function destroy(int $id)
    {
        try {
            $this->categoryRepo->delete($id);
            return response()->json([
                'success' => true,
                'message' => 'Categoría eliminada exitosamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar la categoría porque tiene productos asociados.'
            ], 400);
        }
    }
}
