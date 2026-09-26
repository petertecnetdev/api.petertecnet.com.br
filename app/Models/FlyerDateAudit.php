<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FlyerDateAudit extends Model
{
    protected $fillable = [
        'app_id', 'entity_type', 'entity_id', 'user_id', 'image_fingerprint',
        'expected_start_at', 'expected_start_key', 'timezone', 'locale', 'recurring', 'day_of_week',
        'status', 'result', 'review_action', 'reviewed_at',
    ];

    protected $casts = [
        'expected_start_at' => 'datetime',
        'recurring' => 'boolean',
        'day_of_week' => 'integer',
        'result' => 'array',
        'reviewed_at' => 'datetime',
    ];
}
