<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = [
        'app_id',
        'user_id',
        'email_enabled',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
    ];
}
