<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class SettingRepository
{
    public function getByKey(string $key)
    {
        $result = DB::select("SELECT * FROM settings WHERE `key` = ? LIMIT 1", [$key]);
        return !empty($result) ? $result[0] : null;
    }

    public function set(string $key, ?string $value)
    {
        $exists = $this->getByKey($key);
        $userId = auth()->id();

        if ($exists) {
            DB::update("UPDATE settings SET `value` = ?, updated_by = ?, updated_at = NOW() WHERE `key` = ?", [$value, $userId, $key]);
        } else {
            DB::insert("INSERT INTO settings (`key`, `value`, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())", [$key, $value, $userId, $userId]);
        }

        return $this->getByKey($key);
    }
}
