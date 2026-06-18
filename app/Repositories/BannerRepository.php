<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class BannerRepository
{
    public function getAll()
    {
        return DB::select("SELECT * FROM banners ORDER BY sort_order ASC, id DESC");
    }

    public function getActive()
    {
        return DB::select("SELECT * FROM banners WHERE is_active = 1 ORDER BY sort_order ASC, id DESC");
    }

    public function findById(int $id)
    {
        $result = DB::select("SELECT * FROM banners WHERE id = ? LIMIT 1", [$id]);
        return !empty($result) ? $result[0] : null;
    }

    public function create(array $data)
    {
        $userId = auth()->id();
        DB::insert("
            INSERT INTO banners (image_url, is_active, sort_order, created_by, updated_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ", [
            $data['image_url'],
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
            isset($data['sort_order']) ? (int)$data['sort_order'] : 0,
            $userId,
            $userId
        ]);

        $id = DB::getPdo()->lastInsertId();
        return $this->findById((int) $id);
    }

    public function update(int $id, array $data)
    {
        $fields = [];
        $params = [];

        if (isset($data['image_url'])) {
            $fields[] = "image_url = ?";
            $params[] = $data['image_url'];
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = (int)$data['is_active'];
        }
        if (isset($data['sort_order'])) {
            $fields[] = "sort_order = ?";
            $params[] = (int)$data['sort_order'];
        }

        $userId = auth()->id();
        $fields[] = "updated_by = ?";
        $params[] = $userId;

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $fieldsStr = implode(', ', $fields);
        DB::update("UPDATE banners SET $fieldsStr WHERE id = ?", $params);

        return $this->findById($id);
    }

    public function delete(int $id)
    {
        return DB::delete("DELETE FROM banners WHERE id = ?", [$id]);
    }

    public function updateSortOrder(array $orderList)
    {
        DB::beginTransaction();
        try {
            $userId = auth()->id();
            foreach ($orderList as $index => $id) {
                DB::update("UPDATE banners SET sort_order = ?, updated_by = ?, updated_at = NOW() WHERE id = ?", [$index, $userId, (int) $id]);
            }
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
