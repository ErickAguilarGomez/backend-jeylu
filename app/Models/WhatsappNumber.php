<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappNumber extends Model
{
    protected $table = 'whatsapp_numbers';

    protected $fillable = [
        'alias',
        'phone',
        'is_active',
        'display_order',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer'
    ];
}
