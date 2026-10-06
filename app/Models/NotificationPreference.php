<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id', 'app_id', 'in_app_enabled', 'email_enabled',
        'whatsapp_enabled', 'marketing_whatsapp_enabled',
    ];

    protected $casts = [
        'in_app_enabled' => 'boolean', 'email_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean', 'marketing_whatsapp_enabled' => 'boolean',
    ];
}
