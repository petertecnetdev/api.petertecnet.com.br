<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportantEventRead extends Model
{
    protected $fillable = ['important_event_id','user_id','read_at'];
    protected $casts = ['important_event_id'=>'integer','user_id'=>'integer','read_at'=>'datetime'];
    public function event(){ return $this->belongsTo(ImportantEvent::class,'important_event_id'); }
    public function user(){ return $this->belongsTo(User::class); }
}
