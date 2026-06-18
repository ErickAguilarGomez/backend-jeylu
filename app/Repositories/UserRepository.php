<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserRepository
{
    public function getPaginated(int $page = 1, int $perPage = 10, string $search = '')
    {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $countParams = [];

        $countQuery = "
            SELECT COUNT(u.id) as total 
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE 1=1
        ";
        $selectQuery = "
            SELECT u.id, u.name, u.email, u.role_id, r.name as role_name, u.created_at, u2.name as created_by_name,
                   su.store_id, s.name as store_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            LEFT JOIN users u2 ON u.created_by = u2.id
            LEFT JOIN store_user su ON u.id = su.user_id AND su.is_primary = 1
            LEFT JOIN stores s ON su.store_id = s.id
            WHERE 1=1
        ";

        if ($search !== '') {
            $searchCondition = " AND (u.name LIKE ? OR u.email LIKE ? OR r.name LIKE ?)";
            $countQuery .= $searchCondition;
            $selectQuery .= $searchCondition;
            $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
            $countParams = array_merge($countParams, ["%$search%", "%$search%", "%$search%"]);
        }

        $selectQuery .= " ORDER BY u.id DESC LIMIT ? OFFSET ?";
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
            SELECT u.id, u.name, u.email, u.role_id, r.name as role_name, u.created_at, u2.name as created_by_name,
                   su.store_id, s.name as store_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            LEFT JOIN users u2 ON u.created_by = u2.id
            LEFT JOIN store_user su ON u.id = su.user_id AND su.is_primary = 1
            LEFT JOIN stores s ON su.store_id = s.id
            ORDER BY u.id ASC
        ");
    }

    public function findById(int $id)
    {
        $result = DB::select("SELECT * FROM users WHERE id = ?", [$id]);
        return !empty($result) ? $result[0] : null;
    }

    public function create(array $data, int $creatorId)
    {
        $hashed = Hash::make($data['password']);
        DB::insert("INSERT INTO users (role_id, name, email, password, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())", [
            $data['role_id'], $data['name'], $data['email'], $hashed, $creatorId, $creatorId
        ]);

        $id = DB::getPdo()->lastInsertId();
        return $this->findById((int) $id);
    }

    public function update(int $id, array $data, int $updaterId)
    {
        $params = [$data['role_id'], $data['name'], $data['email'], $updaterId, $id];
        $sql = "UPDATE users SET role_id = ?, name = ?, email = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";

        if (!empty($data['password'])) {
            $hashed = Hash::make($data['password']);
            $params = [$data['role_id'], $data['name'], $data['email'], $hashed, $updaterId, $id];
            $sql = "UPDATE users SET role_id = ?, name = ?, email = ?, password = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
        }

        return DB::update($sql, $params);
    }

    public function delete(int $id)
    {
        return DB::delete("DELETE FROM users WHERE id = ?", [$id]);
    }

    public function getRoles()
    {
        return DB::select("SELECT id, name FROM roles ORDER BY id ASC");
    }
}
