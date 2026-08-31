<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CutinappArtist extends Model
{
    protected $table = 'cutinapp_artists';

    protected $fillable = [
        'app_id', 'user_id', 'slug', 'stage_name', 'bio', 'city', 'uf', 'genres',
        'photo', 'cover', 'instagram_url', 'youtube_url', 'spotify_url', 'website_url',
        'is_published',
    ];

    protected $casts = [
        'genres' => 'array',
        'is_published' => 'boolean',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'cutinapp_event_artist', 'artist_id', 'event_id')
            ->withPivot(['participation_type', 'stage', 'scheduled_at', 'description', 'sort_order', 'is_headliner'])
            ->withTimestamps();
    }
}
