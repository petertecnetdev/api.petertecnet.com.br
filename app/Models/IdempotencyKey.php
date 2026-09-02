<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'api_project_id', 'application_id', 'user_id', 'context_key', 'key', 'request_fingerprint',
        'response_status', 'response_body', 'locked_at', 'expires_at',
    ];

    protected $casts = ['locked_at' => 'datetime', 'expires_at' => 'datetime'];
}
