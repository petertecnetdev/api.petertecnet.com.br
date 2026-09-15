<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id',
        'user_id',
        'plan_id',
        'status',
        'provider',
        'provider_reference',
        'starts_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancelled_at',
        'ended_at',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'current_period_starts_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function scopeForApplication($query, int $appId)
    {
        return $query->where('app_id', $appId);
    }

    public function scopeActive($query)
    {
        $now = now();

        return $query->where('status', 'active')
            ->whereNull('ended_at')
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('current_period_starts_at')
                    ->orWhere('current_period_starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('current_period_ends_at')
                    ->orWhere('current_period_ends_at', '>', $now);
            });
    }

    public function entitlement(string $key, mixed $default = null): mixed
    {
        $entitlements = $this->plan?->entitlements;

        if (! is_array($entitlements)) {
            return $default;
        }

        return data_get($entitlements, $key, $default);
    }

    public function hasEntitlement(string $key): bool
    {
        $value = $this->entitlement($key, false);

        if (is_bool($value)) {
            return $value;
        }

        return $value !== null && $value !== false;
    }
}
