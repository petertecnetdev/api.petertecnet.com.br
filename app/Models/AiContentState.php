<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiContentState extends Model
{
    protected $fillable = [
        'app_id', 'entity_type', 'entity_id', 'user_draft', 'latest_ai_text',
        'latest_generation_id', 'accepted_text', 'prompt_version', 'metadata',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'entity_id' => 'integer',
        'latest_generation_id' => 'integer',
        'metadata' => 'array',
    ];
}
