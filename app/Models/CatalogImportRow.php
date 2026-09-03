<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogImportRow extends Model
{
    protected $fillable = [
        'catalog_import_id', 'row_number', 'raw_data', 'normalized_data', 'matched_product_variant_id',
        'confidence', 'status', 'issues',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'normalized_data' => 'array',
        'issues' => 'array',
        'confidence' => 'decimal:2',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(CatalogImport::class, 'catalog_import_id');
    }

    public function matchedVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'matched_product_variant_id');
    }
}
