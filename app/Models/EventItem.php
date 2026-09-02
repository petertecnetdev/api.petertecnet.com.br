<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventItem extends Model
{
    protected $table = 'event_items';
    protected $fillable = ['event_id','name','description','price','quantity','is_active'];
    protected $casts = ['price'=>'decimal:2','quantity'=>'integer','is_active'=>'boolean'];
    public function event(){return $this->belongsTo(Event::class);}
}
