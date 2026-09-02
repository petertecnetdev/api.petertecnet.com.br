<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtistMember extends Model
{
    protected $table='artist_members';
    protected $fillable=['app_id','artist_id','member_artist_id','display_name','role','photo','bio','sort_order','is_current','joined_at','left_at'];
    protected $casts=['sort_order'=>'integer','is_current'=>'boolean','joined_at'=>'date','left_at'=>'date'];
    public function application(){return $this->belongsTo(Application::class,'app_id');} public function artist(){return $this->belongsTo(Artist::class);} public function linkedArtist(){return $this->belongsTo(Artist::class,'member_artist_id');}
}
