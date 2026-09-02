<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiUsageRecord extends Model
{
    protected $fillable = [
        'api_project_id', 'application_id', 'user_id', 'request_id', 'method', 'route',
        'status_code', 'duration_ms', 'request_bytes', 'response_bytes', 'environment', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];
}
