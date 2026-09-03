<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogImport extends Model
{
    protected $fillable = [
        'public_id', 'application_id', 'establishment_id', 'user_id', 'source_type', 'filename', 'status',
        'total_rows', 'ready_rows', 'review_rows', 'imported_rows', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function rows(): HasMany
    {
        return $this->hasMany(CatalogImportRow::class);
    }
}
