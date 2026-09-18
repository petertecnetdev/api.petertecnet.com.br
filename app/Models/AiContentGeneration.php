<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiContentGeneration extends Model
{
    protected $fillable = [
        'group_id', 'app_id', 'user_id', 'entity_type', 'entity_id', 'action',
        'candidate_index', 'prompt_version', 'model', 'draft_before', 'output',
        'quality_score', 'quality_details', 'status', 'context_hash', 'applied_at',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'user_id' => 'integer',
        'entity_id' => 'integer',
        'candidate_index' => 'integer',
        'quality_score' => 'integer',
        'quality_details' => 'array',
        'applied_at' => 'datetime',
    ];
}
