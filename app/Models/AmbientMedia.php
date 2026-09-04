<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AmbientMedia extends Model
{
    protected $table = 'ambient_media';

    protected $fillable = [
        'app_id', 'subject_type', 'subject_id', 'slot', 'provider', 'source_url',
        'external_id', 'title', 'artist', 'thumbnail_url', 'enabled', 'autoplay',
        'loop', 'volume', 'start_seconds', 'metadata',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'subject_id' => 'integer',
        'enabled' => 'boolean',
        'autoplay' => 'boolean',
        'loop' => 'boolean',
        'volume' => 'integer',
        'start_seconds' => 'integer',
        'metadata' => 'array',
    ];
}
