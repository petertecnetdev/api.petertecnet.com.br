<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * @deprecated Production is no longer a persistence entity.
 *
 * This adapter exists only while legacy call sites are migrated. Every query
 * reads and writes the establishments table and is restricted to
 * type=production, so there is a single organization aggregate in storage.
 */
class Production extends Establishment
{
    protected $table = 'establishments';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('production_type', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('type'), 'production');
        });

        static::creating(function (Production $production): void {
            $production->type = 'production';
        });
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'establishment_id');
    }
}
