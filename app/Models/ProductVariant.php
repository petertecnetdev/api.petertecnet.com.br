<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'public_id', 'name', 'sku', 'gtin', 'specifications', 'package_quantity',
        'package_unit', 'source', 'source_confidence', 'metadata',
    ];

    protected $casts = [
        'specifications' => 'array',
        'metadata' => 'array',
        'package_quantity' => 'decimal:3',
        'source_confidence' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Item::class, 'product_variant_id');
    }
}
