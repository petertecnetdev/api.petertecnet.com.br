<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'user_id',
        'application',
        'plan_code',
        'plan_name',
        'price_cents',
        'currency',
        'billing_interval',
        'billing_interval_count',
        'source',
        'handoff_channel',
        'status',
        'idempotency_key',
        'metadata',
        'checkout_started_at',
        'payment_pending_at',
        'paid_at',
        'activated_at',
        'abandoned_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'checkout_started_at' => 'datetime',
        'payment_pending_at' => 'datetime',
        'paid_at' => 'datetime',
        'activated_at' => 'datetime',
        'abandoned_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
