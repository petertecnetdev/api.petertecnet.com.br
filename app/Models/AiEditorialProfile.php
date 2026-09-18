<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiEditorialProfile extends Model
{
    protected $fillable = [
        'app_id', 'scope_type', 'scope_id', 'traits', 'avoid_phrases',
        'source_count', 'source_updated_at',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'scope_id' => 'integer',
        'traits' => 'array',
        'avoid_phrases' => 'array',
        'source_count' => 'integer',
        'source_updated_at' => 'datetime',
    ];
}
