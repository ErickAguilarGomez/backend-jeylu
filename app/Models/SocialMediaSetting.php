<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialMediaSetting extends Model
{
    protected $table = 'social_media_settings';

    protected $fillable = [
        'type',
        'url',
        'phone',
        'default_message',
        'icon',
        'active',
        'sort_order',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer'
    ];
}
