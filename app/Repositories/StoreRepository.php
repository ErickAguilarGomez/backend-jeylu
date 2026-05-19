<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class StoreRepository
{
    public function getPaginated(int $page = 1, int $perPage = 10, string $search = '')
    {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $countParams = [];

        $countQuery = "SELECT COUNT(s.id) as total FROM stores s WHERE 1=1";
        $selectQuery = "
            SELECT s.id, s.name, s.address, s.phone, s.created_at, u.name as created_by_name
            FROM stores s
            LEFT JOIN users u ON s.created_by = u.id
            WHERE 1=1
        ";

        if ($search !== '') {
            $searchCondition = " AND (s.name LIKE ? OR s.address LIKE ?)";
            $countQuery .= $searchCondition;
            $selectQuery .= $searchCondition;
            $params = array_merge($params, ["%$search%", "%$search%"]);
            $countParams = array_merge($countParams, ["%$search%", "%$search%"]);
        }

        $selectQuery .= " ORDER BY s.id DESC LIMIT ? OFFSET ?";
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
            SELECT s.id, s.name, s.address, s.phone, s.created_at, u.name as created_by_name
            FROM stores s
            LEFT JOIN users u ON s.created_by = u.id
            ORDER BY s.id ASC
        ");
    }

    public function findById(int $id)
    {
        $result = DB::select("SELECT * FROM stores WHERE id = ?", [$id]);
        return !empty($result) ? $result[0] : null;
    }

    public function create(array $data, int $userId)
    {
        DB::insert("INSERT INTO stores (name, address, phone, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())", [
            $data['name'], $data['address'], $data['phone'] ?? null, $userId, $userId
        ]);

        $inserted = DB::select("SELECT * FROM stores WHERE name = ? ORDER BY id DESC LIMIT 1", [$data['name']]);
        return $inserted[0] ?? null;
    }

    public function update(int $id, array $data, int $userId)
    {
        return DB::update("UPDATE stores SET name = ?, address = ?, phone = ?, updated_by = ?, updated_at = NOW() WHERE id = ?", [
            $data['name'], $data['address'], $data['phone'] ?? null, $userId, $id
        ]);
    }

    public function delete(int $id)
    {
        return DB::delete("DELETE FROM stores WHERE id = ?", [$id]);
    }

    public function assignUser(int $storeId, int $userId, bool $isPrimary, int $assignedBy)
    {
        if ($isPrimary) {
            DB::update("UPDATE store_user SET is_primary = 0 WHERE user_id = ?", [$userId]);
        }

        $exists = DB::select("SELECT id FROM store_user WHERE store_id = ? AND user_id = ?", [$storeId, $userId]);
        if (!empty($exists)) {
            return DB::update("UPDATE store_user SET is_primary = ?, assigned_by = ?, updated_at = NOW() WHERE id = ?", [
                $isPrimary ? 1 : 0, $assignedBy, $exists[0]->id
            ]);
        }

        return DB::insert("INSERT INTO store_user (store_id, user_id, is_primary, assigned_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())", [
            $storeId, $userId, $isPrimary ? 1 : 0, $assignedBy
        ]);
    }

    public function unassignUser(int $storeId, int $userId)
    {
        return DB::delete("DELETE FROM store_user WHERE store_id = ? AND user_id = ?", [$storeId, $userId]);
    }

    public function getStoreUsers(int $storeId)
    {
        return DB::select("
            SELECT u.id, u.name, u.email, r.name as role_name, su.is_primary
            FROM store_user su
            INNER JOIN users u ON su.user_id = u.id
            INNER JOIN roles r ON u.role_id = r.id
            WHERE su.store_id = ?
            ORDER BY u.name ASC
        ", [$storeId]);
    }

    public function getUserStores(int $userId)
    {
        return DB::select("
            SELECT s.id, s.name, s.address, su.is_primary
            FROM store_user su
            INNER JOIN stores s ON su.store_id = s.id
            WHERE su.user_id = ?
            ORDER BY su.is_primary DESC, s.name ASC
        ", [$userId]);
    }
}
