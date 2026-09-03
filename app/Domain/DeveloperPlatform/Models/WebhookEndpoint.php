<?php

namespace App\Domain\DeveloperPlatform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    protected $fillable = [
        'api_client_id',
        'url',
        'events',
        'secret',
        'status',
        'last_success_at',
        'last_failure_at',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'secret' => 'encrypted',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
