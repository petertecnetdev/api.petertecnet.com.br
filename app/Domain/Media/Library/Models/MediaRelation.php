<?php

namespace App\Domain\Media\Library\Models;

use Illuminate\Database\Eloquent\Model;

final class MediaRelation extends Model
{
    protected $fillable = [
        'media_asset_id', 'application_id', 'entity_type', 'entity_id',
        'role', 'sort_order', 'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function asset()
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
