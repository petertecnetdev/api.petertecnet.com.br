<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CutinappArtist extends Model
{
    protected $table = 'cutinapp_artists';

    protected $fillable = [
        'app_id', 'user_id', 'created_by_user_id', 'claimed_at', 'slug', 'artist_type', 'stage_name', 'bio', 'city', 'uf', 'genres',
        'photo', 'cover', 'instagram_url', 'youtube_url', 'spotify_url', 'website_url',
        'is_published',
    ];

    protected $casts = [
        'genres' => 'array',
        'is_published' => 'boolean',
        'claimed_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function claims()
    {
        return $this->hasMany(CutinappArtistClaim::class, 'artist_id');
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'cutinapp_event_artist', 'artist_id', 'event_id')
            ->withPivot(['participation_type', 'stage', 'scheduled_at', 'description', 'sort_order', 'is_headliner'])
            ->withTimestamps();
    }

    public function members()
    {
        return $this->hasMany(CutinappArtistMember::class, 'artist_id')
            ->orderBy('sort_order')
            ->orderBy('display_name');
    }

    public function groupMemberships()
    {
        return $this->hasMany(CutinappArtistMember::class, 'member_artist_id');
    }

    public function isGroup(): bool
    {
        return in_array($this->artist_type, ['band', 'group', 'duo', 'collective', 'orchestra'], true);
    }

    public function isClaimed(): bool
    {
        return ! is_null($this->user_id);
    }
}
