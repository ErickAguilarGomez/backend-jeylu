<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class CategoryRepository
{
    public function getPaginated(int $page = 1, int $perPage = 10, string $search = '')
    {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $countParams = [];

        $countQuery = "SELECT COUNT(c.id) as total FROM categories c WHERE 1=1";
        $selectQuery = "
            SELECT c.id, c.name, c.description, c.unit_of_measure, c.created_at, u.name as created_by_name
            FROM categories c
            LEFT JOIN users u ON c.created_by = u.id
            WHERE 1=1
        ";

        if ($search !== '') {
            $searchCondition = " AND (c.name LIKE ? OR c.description LIKE ?)";
            $countQuery .= $searchCondition;
            $selectQuery .= $searchCondition;
            $params = array_merge($params, ["%$search%", "%$search%"]);
            $countParams = array_merge($countParams, ["%$search%", "%$search%"]);
        }

        $selectQuery .= " ORDER BY c.id DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $totalCount = DB::select($countQuery, $countParams)[0]->total;
        $data = DB::select($selectQuery, $params);
        $lastPage = (int) ceil($totalCount / $perPage);

        return [
            'data' => $data,
            'total' => $totalCount,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage > 0 ? $lastPage : 1
        ];
    }

    public function getAll()
    {
        return DB::select("
            SELECT c.id, c.name, c.description, c.unit_of_measure, c.created_at, u.name as created_by_name,
                   (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as products_count
            FROM categories c
            LEFT JOIN users u ON c.created_by = u.id
            ORDER BY c.name ASC
        ");
    }

    public function findById(int $id)
    {
        $result = DB::select("SELECT * FROM categories WHERE id = ?", [$id]);
        return !empty($result) ? $result[0] : null;
    }

    public function create(array $data, int $userId)
    {
        $timestamp = now();
        $unitOfMeasure = $data['unit_of_measure'] ?? null;

        DB::insert("INSERT INTO categories (name, description, unit_of_measure, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
            $data['name'], $data['description'] ?? null, $unitOfMeasure, $userId, $userId, $timestamp, $timestamp
        ]);

        $id = DB::getPdo()->lastInsertId();
        return $this->findById((int) $id);
    }

    public function update(int $id, array $data, int $userId)
    {
        $unitOfMeasure = $data['unit_of_measure'] ?? null;

        return DB::update("UPDATE categories SET name = ?, description = ?, unit_of_measure = ?, updated_by = ?, updated_at = ? WHERE id = ?", [
            $data['name'], $data['description'] ?? null, $unitOfMeasure, $userId, now(), $id
        ]);
    }

    public function delete(int $id)
    {
        return DB::delete("DELETE FROM categories WHERE id = ?", [$id]);
    }
}
