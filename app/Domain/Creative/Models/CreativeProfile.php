<?php

namespace App\Domain\Creative\Models;

use Illuminate\Database\Eloquent\Model;

final class CreativeProfile extends Model
{
    protected $fillable = [
        'application_id', 'owner_type', 'owner_id', 'brand_colors',
        'preferred_style', 'preferred_intensity', 'brand_context', 'preferences',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'owner_id' => 'integer',
        'brand_colors' => 'array',
        'preferences' => 'array',
    ];
}
