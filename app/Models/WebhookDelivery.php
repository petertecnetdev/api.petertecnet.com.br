<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'public_id', 'webhook_endpoint_id', 'event', 'status', 'payload', 'attempts',
        'response_status', 'response_body', 'next_attempt_at', 'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (WebhookDelivery $delivery) => $delivery->public_id ??= (string) Str::uuid());
    }

    public function endpoint() { return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id'); }
}
