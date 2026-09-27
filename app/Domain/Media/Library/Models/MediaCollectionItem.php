<?php

namespace App\Domain\Media\Library\Models;

use Illuminate\Database\Eloquent\Model;

final class MediaCollectionItem extends Model
{
    protected $fillable = [
        'media_collection_id', 'media_asset_id', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];
}
