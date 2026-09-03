<?php

namespace App\Domain\DeveloperPlatform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'client_id',
        'environment',
        'status',
        'scopes',
        'allowed_origins',
        'rate_limit_per_minute',
        'last_used_at',
        'terms_accepted_at',
        'privacy_acknowledged_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'allowed_origins' => 'array',
        'last_used_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'privacy_acknowledged_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function keys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
