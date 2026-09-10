<?php

namespace App\Domain\Creative\Models;

use Illuminate\Database\Eloquent\Model;

final class CreativeGeneration extends Model
{
    protected $fillable = [
        'application_id', 'user_id', 'owner_type', 'owner_id', 'purpose', 'subject',
        'format', 'style', 'intensity', 'variation', 'generation_mode', 'model',
        'quality_score', 'selected', 'metadata',
    ];

    protected $casts = [
        'application_id' => 'integer', 'user_id' => 'integer', 'owner_id' => 'integer',
        'quality_score' => 'integer', 'selected' => 'boolean', 'metadata' => 'array',
    ];
}
