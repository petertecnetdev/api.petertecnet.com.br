<?php

namespace App\Domain\Media\Library\Models;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MediaAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'application_id', 'owner_user_id', 'name', 'title',
        'description', 'alt_text', 'kind', 'category', 'purpose',
        'mime_type', 'extension', 'file_size', 'width', 'height',
        'duration_ms', 'storage_disk', 'storage_path', 'public_url',
        'checksum', 'visibility', 'status', 'is_official',
        'is_marketing_approved', 'is_ai_generated', 'metadata',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'is_official' => 'boolean',
        'is_marketing_approved' => 'boolean',
        'is_ai_generated' => 'boolean',
        'metadata' => 'array',
        'approved_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function variants()
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function relations()
    {
        return $this->hasMany(MediaRelation::class);
    }

    public function collections()
    {
        return $this->belongsToMany(
            MediaCollection::class,
            'media_collection_items',
            'media_asset_id',
            'media_collection_id'
        )->withPivot(['sort_order', 'metadata'])->withTimestamps();
    }
}
