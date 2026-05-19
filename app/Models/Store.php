<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = ['name', 'address', 'phone', 'created_by', 'updated_by'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'store_user');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_store')->withPivot('stock')->withTimestamps();
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
