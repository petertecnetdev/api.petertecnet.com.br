<?php

namespace App\Domain\Media\Library\Models;

use Illuminate\Database\Eloquent\Model;

final class MediaVariant extends Model
{
    protected $fillable = [
        'media_asset_id', 'name', 'mime_type', 'file_size', 'width',
        'height', 'storage_disk', 'storage_path', 'public_url', 'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'metadata' => 'array',
    ];

    public function asset()
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
