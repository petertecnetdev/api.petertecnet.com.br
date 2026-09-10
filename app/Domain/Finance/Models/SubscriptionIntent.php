<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class SubscriptionIntent extends Model
{
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
        'status',
        'source',
        'handoff_channel',
        'idempotency_key',
        'metadata',
        'checkout_started_at',
        'payment_pending_at',
        'paid_at',
        'activated_at',
        'abandoned_at',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'billing_interval_count' => 'integer',
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
