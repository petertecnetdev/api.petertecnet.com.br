<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Artist extends Model
{
    protected $table = 'artists';
    protected $fillable = ['app_id','user_id','claimed_at','slug','artist_type','stage_name','bio','city','uf','genres','photo','cover','instagram_url','youtube_url','spotify_url','website_url','is_published'];
    protected $casts = ['genres'=>'array','is_published'=>'boolean','claimed_at'=>'datetime'];
    public function application(){return $this->belongsTo(Application::class,'app_id');} public function user(){return $this->belongsTo(User::class);} public function claims(){return $this->hasMany(ArtistClaim::class);} public function events(){return $this->belongsToMany(Event::class,'event_artist','artist_id','event_id')->withPivot(['participation_type','stage','scheduled_at','description','sort_order','is_headliner'])->withTimestamps();} public function members(){return $this->hasMany(ArtistMember::class)->orderBy('sort_order')->orderBy('id');}
}
