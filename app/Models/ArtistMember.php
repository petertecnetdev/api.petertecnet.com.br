<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtistMember extends Model
{
    protected $table = 'artist_members';
    protected $fillable = ['artist_id','name','role','bio','photo','instagram_url','is_active','sort_order'];
    protected $casts = ['is_active'=>'boolean','sort_order'=>'integer'];
    public function artist(){return $this->belongsTo(Artist::class);}
}
