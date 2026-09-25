<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id', 'app_id', 'user_id', 'establishment_id', 'channel', 'destination',
        'destination_e164', 'provider', 'provider_message_id', 'template_key', 'template_name',
        'locale', 'status', 'attempt_count', 'correlation_id', 'idempotency_key', 'error_code',
        'error_message', 'queued_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
    ];

    protected $casts = [
        'destination_e164' => 'encrypted',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    public function notification()
    {
        return $this->belongsTo(AppNotification::class, 'notification_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
