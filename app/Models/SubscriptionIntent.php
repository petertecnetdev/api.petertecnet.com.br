<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'user_id',
        'plan_code',
        'source',
        'handoff_channel',
        'status',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
