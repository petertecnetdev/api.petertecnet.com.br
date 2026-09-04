<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RelationshipType extends Model
{
    protected $fillable = ['code', 'name', 'category', 'aliases', 'is_active', 'metadata'];

    protected $casts = [
        'aliases' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function canonicalize(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $type = static::query()->active()->get()->first(function (RelationshipType $type) use ($normalized) {
            if ($type->code === $normalized) return true;
            return collect($type->aliases ?? [])->map(fn ($alias) => strtolower(trim((string) $alias)))->contains($normalized);
        });

        return $type?->code;
    }
}
