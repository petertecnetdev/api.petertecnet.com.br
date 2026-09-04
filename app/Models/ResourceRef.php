<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ResourceRef extends Model
{
    protected $fillable = [
        'uuid', 'application_id', 'establishment_id', 'resource_type', 'resource_id', 'label', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (ResourceRef $resource) {
            $resource->uuid ??= (string) Str::uuid();
            $resource->resource_type = strtolower(trim($resource->resource_type));
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(ResourceRelationship::class);
    }
}
