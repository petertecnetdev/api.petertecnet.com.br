<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Artist extends Model
{
    use SoftDeletes;

    protected $table = 'artists';

    protected $fillable = [
        'app_id', 'user_id', 'created_by_user_id', 'claimed_at', 'slug', 'artist_type', 'stage_name',
        'short_bio', 'bio', 'city', 'uf', 'genres', 'photo', 'cover', 'instagram_url', 'youtube_url',
        'spotify_url', 'website_url', 'professional_email', 'professional_phone', 'verification_status',
        'verified_at', 'is_active', 'is_published', 'profile_completion', 'press_kit', 'technical_rider',
        'hospitality_rider', 'settings', 'metadata', 'origin_type', 'origin_id', 'origin_label', 'reference_visible',
        'onboarding_completed_at',
    ];

    protected $hidden = [
        'professional_email', 'professional_phone', 'press_kit', 'technical_rider',
        'hospitality_rider', 'settings', 'metadata', 'created_by_user_id', 'origin_id',
    ];

    protected $casts = [
        'genres' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'claimed_at' => 'datetime',
        'verified_at' => 'datetime',
        'profile_completion' => 'integer',
        'press_kit' => 'array',
        'technical_rider' => 'array',
        'hospitality_rider' => 'array',
        'settings' => 'array',
        'metadata' => 'array',
        'reference_visible' => 'boolean',
        'onboarding_completed_at' => 'datetime',
    ];

    public function application(){return $this->belongsTo(Application::class,'app_id');}
    public function user(){return $this->belongsTo(User::class);}
    public function creator(){return $this->belongsTo(User::class,'created_by_user_id');}
    public function claims(){return $this->hasMany(ArtistClaim::class);}
    public function events(){return $this->belongsToMany(Event::class,'event_artist','artist_id','event_id')->withPivot(['app_id','participation_type','stage','scheduled_at','description','sort_order','is_headliner','status','invited_by_user_id','invited_at','responded_at','checked_in_at','fee_cents','payment_status','invite_token','private_notes','metadata'])->withTimestamps();}
    public function members(){return $this->hasMany(ArtistMember::class)->orderBy('sort_order')->orderBy('id');}
}
