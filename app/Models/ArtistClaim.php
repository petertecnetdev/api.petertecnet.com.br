<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtistClaim extends Model
{
    protected $table = 'artist_claims';
    protected $fillable = ['app_id','artist_id','user_id','status','message','review_note','reviewed_by','reviewed_at'];
    protected $casts = ['reviewed_at'=>'datetime'];
    public function artist(){return $this->belongsTo(Artist::class);} public function user(){return $this->belongsTo(User::class);} public function reviewer(){return $this->belongsTo(User::class,'reviewed_by');}
}
