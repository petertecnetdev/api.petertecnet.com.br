<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportantEvent extends Model
{
    protected $fillable = ['app_id','type','severity','title','message','actor_user_id','reference_type','reference_id','reference_url','dedupe_key','metadata','occurred_at'];
    protected $casts = ['app_id'=>'integer','actor_user_id'=>'integer','metadata'=>'array','occurred_at'=>'datetime'];
    public function application(){ return $this->belongsTo(Application::class,'app_id'); }
    public function actor(){ return $this->belongsTo(User::class,'actor_user_id'); }
    public function reads(){ return $this->hasMany(ImportantEventRead::class); }
}
