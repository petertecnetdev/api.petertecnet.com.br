<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_id', 'app_id', 'user_id', 'establishment_id', 'channel',
        'classification', 'destination_masked', 'provider', 'provider_message_id',
        'template', 'template_locale', 'status', 'attempt_count', 'error_code',
        'error_message', 'idempotency_key', 'correlation_id', 'queued_at', 'sent_at',
        'delivered_at', 'read_at', 'failed_at',
    ];

    protected $casts = [
        'queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime',
        'read_at' => 'datetime', 'failed_at' => 'datetime',
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
