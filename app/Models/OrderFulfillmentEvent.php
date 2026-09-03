<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFulfillmentEvent extends Model
{
    protected $fillable = [
        'order_id',
        'app_id',
        'establishment_id',
        'actor_user_id',
        'event',
        'from_status',
        'to_status',
        'validation_method',
        'result',
        'request_id',
        'session_id_hash',
        'ip_hash',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
