<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id',
        'code',
        'name',
        'description',
        'price',
        'currency',
        'billing_interval',
        'billing_interval_count',
        'entitlements',
        'metadata',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'billing_interval_count' => 'integer',
        'entitlements' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeForApplication($query, int $appId)
    {
        return $query->where('app_id', $appId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
