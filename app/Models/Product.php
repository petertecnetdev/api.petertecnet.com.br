<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'public_id', 'name', 'brand', 'category', 'subcategory', 'description', 'canonical_key', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class);
    }
}
