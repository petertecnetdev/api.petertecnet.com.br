<?php

namespace App\Domain\Media\Library\Models;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MediaCollection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'application_id', 'owner_user_id', 'name', 'slug', 'description',
        'purpose', 'visibility', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function application()
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function assets()
    {
        return $this->belongsToMany(
            MediaAsset::class,
            'media_collection_items',
            'media_collection_id',
            'media_asset_id'
        )->withPivot(['sort_order', 'metadata'])->withTimestamps();
    }
}
