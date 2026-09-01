<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CutinappArtistMember extends Model
{
    protected $table = 'cutinapp_artist_members';

    protected $fillable = [
        'app_id', 'artist_id', 'member_artist_id', 'display_name', 'role', 'photo', 'bio',
        'sort_order', 'is_current', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'joined_at' => 'date',
        'left_at' => 'date',
    ];

    public function artist()
    {
        return $this->belongsTo(CutinappArtist::class, 'artist_id');
    }

    public function linkedArtist()
    {
        return $this->belongsTo(CutinappArtist::class, 'member_artist_id');
    }
}
